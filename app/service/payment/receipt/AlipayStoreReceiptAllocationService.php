<?php

declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\trade\PayOrderRepository;
use support\Db;

/** Database reservations only. No Redis occupancy, network IO or payment dispatch here. */
class AlipayStoreReceiptAllocationService
{
    public function __construct(protected PayOrderRepository $orders) {}

    /**
     * Partition an already-authorized, already-ordered candidate list. The probe is advisory;
     * reserveNewOrder() must re-read occupancy under the shared receipt guard before committing.
     * No config, credential, UID or QR content is copied into the returned route diagnostics.
     */
    public function preview(array $candidates, int $original): array
    {
        $configIds = [];
        foreach ($candidates as $candidate) {
            $channel = $candidate['channel'];
            if ($channel->plugin_code === AlipayStoreReceiptRules::PLUGIN) $configIds[] = (int) $channel->api_config_id;
        }
        $configIds = array_values(array_unique($configIds));
        $availability = [];
        if ($configIds !== []) {
            $configs = PaymentPluginConf::whereIn('id', $configIds)->get()->keyBy('id');
            // Deliberately not limited by merchant, poll group, channel status or this candidate list.
            $scopeChannels = PaymentChannel::where('plugin_code', AlipayStoreReceiptRules::PLUGIN)
                ->whereIn('api_config_id', $configIds)->get(['id', 'api_config_id']);
            $byChannel = $this->orders->listUsedStoreReceiptAmountsByChannel(
                $scopeChannels->pluck('id')->all(), AlipayStoreReceiptRules::date(time())
            );
            $byConfig = [];
            foreach ($scopeChannels as $channel) {
                foreach ($byChannel[(int) $channel->id] ?? [] as $amount) $byConfig[(int) $channel->api_config_id][] = $amount;
            }
            foreach ($configIds as $configId) {
                $conf = $configs->get($configId);
                if (!$conf || $conf->plugin_code !== AlipayStoreReceiptRules::PLUGIN) AlipayStoreReceiptRules::fail('门店账单配置不存在或已更换');
                // Configuration/database failures are NOT reported as occupied amounts.
                $config = AlipayStoreReceiptRules::config((array) $conf->config);
                $maxOffset = (int) $config['amount_offset_max'];
                $used = $byConfig[$configId] ?? [];
                $availability[$configId] = [
                    'original_available' => AlipayStoreReceiptRules::availableAmount($original, $used, 0) !== null,
                    'range_available' => AlipayStoreReceiptRules::availableAmount($original, $used, $maxOffset) !== null,
                    'amount_offset_max' => $maxOffset,
                ];
            }
        }

        $originalCandidates = $offsetCandidates = $rejected = $deferred = [];
        foreach ($candidates as $candidate) {
            $channel = $candidate['channel'];
            if ($channel->plugin_code !== AlipayStoreReceiptRules::PLUGIN) {
                $originalCandidates[] = $candidate;
                continue;
            }
            $state = $availability[(int) $channel->api_config_id];
            $candidate['receipt_scope_id'] = (int) $channel->api_config_id;
            $candidate['receipt_amount_state'] = $state['original_available'] ? 'original_available'
                : ($state['range_available'] ? 'offset_only' : 'occupied');
            $candidate['receipt_amount_message'] = $state['original_available'] ? '原金额可用'
                : ($state['range_available'] ? '原金额已占用，优先尝试其他门店后再按配置偏移' : '该金额及允许偏移均已占用');
            if ($state['original_available']) $originalCandidates[] = $candidate;
            // Include initially-free stores too: a concurrent request may win their original amount.
            if ($state['range_available'] && $state['amount_offset_max'] > 0) $offsetCandidates[] = $candidate;
            if (!$state['original_available']) $deferred[] = $candidate;
            if (!$state['range_available']) {
                $candidate['available'] = false;
                $candidate['reject_reasons'][] = $candidate['receipt_amount_message'];
                $candidate['reject_reason_text'] = implode('；', $candidate['reject_reasons']);
                $rejected[] = $candidate;
            }
        }
        return ['original' => $originalCandidates, 'offset' => $offsetCandidates, 'rejected' => $rejected, 'deferred' => $deferred];
    }

    /** Caller holds AlipayReceiptGuard from before creating the business order or freezing fees. */
    public function reserveNewOrder(PayOrder $order, PaymentChannel $selected, bool $allowOffset): array
    {
        if (Db::connection()->transactionLevel() === 0) throw new \LogicException('门店金额分配必须在创建支付单的受保护事务中执行');
        $live = PaymentChannel::whereKey($selected->id)->lockForUpdate()->first();
        if (!$live || (int) $live->status !== 1) AlipayStoreReceiptRules::fail('门店收款通道不存在或已停用');
        foreach (['plugin_code', 'api_config_id', 'pay_type_id', 'merchant_id', 'channel_mode', 'split_rate_bp', 'min_amount', 'max_amount'] as $key) {
            $changed = $key === 'plugin_code' ? (string) $live->$key !== (string) $selected->$key : (int) $live->$key !== (int) $selected->$key;
            if ($changed) AlipayStoreReceiptRules::fail('门店收款通道配置已变化，请重试');
        }
        $config = $this->lockedConfig((int) $live->api_config_id);
        AlipayReceiptGuard::topology();
        return $this->allocateLocked($order, $config, $this->scopeChannelIds((int) $live->api_config_id), $allowOffset);
    }

    /** Also supports pre-existing, unprepared orders; an existing snapshot is never reallocated. */
    public function prepareForPresentation(string $payNo, array $runtimeConfig): array
    {
        return AlipayReceiptGuard::atomic(function () use ($payNo, $runtimeConfig) {
            $configId = (int) ($runtimeConfig['api_config_id'] ?? 0);
            $config = $this->lockedConfig($configId);
            foreach (['app_id', 'bill_user_id', 'store_no', 'receipt_match_mode', 'receipt_qrcode_content', 'receipt_qrcode_image'] as $key) {
                if (($config[$key] ?? '') !== ($runtimeConfig[$key] ?? '')) AlipayStoreReceiptRules::fail('门店账单配置已变化，请重试');
            }
            AlipayReceiptGuard::topology();
            $order = $this->orders->findForUpdateByPayNo($payNo);
            if (!$order) AlipayStoreReceiptRules::fail('支付单不存在');
            return $this->allocateLocked($order, $config, $this->scopeChannelIds($configId), true);
        });
    }

    private function lockedConfig(int $configId): array
    {
        $conf = PaymentPluginConf::whereKey($configId)->lockForUpdate()->first();
        if (!$conf || $conf->plugin_code !== AlipayStoreReceiptRules::PLUGIN) AlipayStoreReceiptRules::fail('门店账单配置不存在或已更换');
        return AlipayStoreReceiptRules::config((array) $conf->config);
    }

    private function scopeChannelIds(int $configId): array
    {
        return PaymentChannel::where('plugin_code', AlipayStoreReceiptRules::PLUGIN)->where('api_config_id', $configId)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function allocateLocked(PayOrder $order, array $config, array $channelIds, bool $allowOffset): array
    {
        $now = time();
        if (!in_array((int) $order->status, TradeConstant::orderMutableStatuses(), true)) AlipayStoreReceiptRules::fail('支付单状态不允许重新分配金额');
        if ($order->plugin_code !== AlipayStoreReceiptRules::PLUGIN || !in_array((int) $order->channel_id, $channelIds, true)) AlipayStoreReceiptRules::fail('支付单不属于当前收款配置');
        $ext = (array) ($order->ext_json ?? []);
        if (isset($ext['personal_receipt'])) {
            $meta = (array) $ext['personal_receipt'];
            if (($meta['platform'] ?? '') !== AlipayStoreReceiptRules::PLUGIN || ($meta['bill_user_id'] ?? '') !== $config['bill_user_id'] || ($meta['store_no'] ?? '') !== $config['store_no']) AlipayStoreReceiptRules::fail('支付单门店快照不一致');
            if (AlipayStoreReceiptRules::timestamp($meta['expire_at'] ?? null) <= $now) AlipayStoreReceiptRules::fail('支付单已过期，请重新下单');
            if ((string) $order->request_at !== ($meta['request_at'] ?? '') || (string) $order->expire_at !== ($meta['expire_at'] ?? '') || (int) $order->pay_amount !== ($meta['receipt_amount'] ?? 0)) AlipayStoreReceiptRules::fail('支付单识别快照已变化');
            return $meta; // Never extend expiry, change the amount or replace a displayed QR.
        }
        $original = (int) $order->pay_amount;
        if ($original <= 0) AlipayStoreReceiptRules::fail('支付单金额必须大于零');
        $used = $this->orders->listUsedStoreReceiptAmounts($channelIds, (string) $order->pay_no, AlipayStoreReceiptRules::date($now));
        $amount = AlipayStoreReceiptRules::allocate($original, $used, $allowOffset ? (int) $config['amount_offset_max'] : 0);
        $start = AlipayStoreReceiptRules::timestamp((string) $order->request_at);
        $expires = $now + (int) $config['receipt_valid_seconds'];
        if ($order->expire_at) $expires = min($expires, AlipayStoreReceiptRules::timestamp((string) $order->expire_at));
        if ($expires <= $now || $start > $now + 5) AlipayStoreReceiptRules::fail('支付单识别时间无效');
        $meta = ['platform' => AlipayStoreReceiptRules::PLUGIN, 'mode' => 'amount', 'bill_user_id' => $config['bill_user_id'],
            'store_no' => $config['store_no'], 'original_amount' => $original, 'receipt_amount' => $amount,
            'offset_amount' => $amount - $original, 'request_at' => (string) $order->request_at, 'expire_at' => AlipayStoreReceiptRules::date($expires),
            'qrcode' => trim((string) ($config['receipt_qrcode_content'] ?? '')), 'qrcode_image' => trim((string) ($config['receipt_qrcode_image'] ?? ''))];
        $ext['personal_receipt'] = $meta;
        $ext['receipt_watcher'] = array_diff_key($meta, array_flip(['qrcode', 'qrcode_image']));
        $order->ext_json = $ext;
        $order->pay_amount = $amount;
        $order->expire_at = $meta['expire_at'];
        $order->save();
        return $meta;
    }
}
