<?php

declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\constant\TradeConstant;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentChannel;
use app\model\payment\PayOrder;
use support\Db;

/** Short database transactions only: no network IO while holding this guard. */
final class AlipayReceiptGuard
{
    public static function atomic(callable $callback): mixed
    {
        return Db::transaction(function () use ($callback) {
            // A durable singleton serializes configuration changes, allocation and final matching.
            // Locking an absent row is not sufficient; the migration seeds this row.
            if (!Db::table('ma_alipay_receipt_guard')->where('id', 1)->lockForUpdate()->first()) {
                AlipayStoreReceiptRules::fail('门店账单数据库未升级，请先执行 php webman migrate');
            }
            return $callback();
        });
    }

    public static function hasPending(array $channelIds): bool
    {
        return $channelIds !== [] && PayOrder::whereIn('channel_id', $channelIds)->whereIn('status', TradeConstant::orderMutableStatuses())->exists();
    }

    public static function channelIds(int $configId): array
    {
        return PaymentChannel::where('api_config_id', $configId)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Validate the post-write topology inside the same transaction; failure rolls back the write. */
    public static function topology(): void
    {
        $configs = PaymentPluginConf::whereIn('plugin_code', [AlipayStoreReceiptRules::PLUGIN, AlipayStoreReceiptRules::LEGACY])->get()->keyBy('id');
        $active = PaymentChannel::whereIn('plugin_code', [AlipayStoreReceiptRules::PLUGIN, AlipayStoreReceiptRules::LEGACY])->where('status', 1)->get();
        $byUid = [];
        foreach ($active as $channel) {
            $conf = $configs->get((int) $channel->api_config_id);
            if (!$conf || $conf->plugin_code !== $channel->plugin_code) AlipayStoreReceiptRules::fail('支付宝监听通道的配置归属不正确');
            $c = (array) $conf->config;
            if ($conf->plugin_code === AlipayStoreReceiptRules::PLUGIN) $c = AlipayStoreReceiptRules::config($c);
            $uid = trim((string) ($c['bill_user_id'] ?? ''));
            if ($uid !== '') $byUid[$uid][(int) $conf->id] = ['plugin' => $conf->plugin_code, 'store' => $c['store_no'] ?? ''];
        }
        foreach ($byUid as $uid => $members) {
            $plugins = array_unique(array_column($members, 'plugin'));
            if (count($plugins) > 1) AlipayStoreReceiptRules::fail('同一支付宝账号不能同时启用账号账单与门店账单通道');
            $stores = [];
            foreach ($members as $id => $member) {
                if ($member['plugin'] === AlipayStoreReceiptRules::PLUGIN) {
                    if (isset($stores[$member['store']]) && $stores[$member['store']] !== $id) AlipayStoreReceiptRules::fail('同一账号同一门店不能启用多份配置，请让多个通道引用同一配置');
                    $stores[$member['store']] = $id;
                }
                // A disabled channel may still have an outstanding order. Drain it before changing scope.
                foreach ($configs as $other) {
                    if ((int) $other->id === $id) continue;
                    $oc = (array) $other->config;
                    if (trim((string) ($oc['bill_user_id'] ?? '')) !== (string) $uid) continue;
                    $overlaps = $other->plugin_code !== $member['plugin'] || ($member['plugin'] === AlipayStoreReceiptRules::PLUGIN && ($oc['store_no'] ?? '') === $member['store']);
                    if ($overlaps && self::hasPending(self::channelIds((int) $other->id))) AlipayStoreReceiptRules::fail('重叠收款范围仍有未结束支付单，请先处理后再启用');
                }
            }
        }
    }

    public static function saveConfig(PaymentPluginConf $model, callable $save): mixed
    {
        return self::atomic(function () use ($model, $save) {
            $current = $model->exists ? PaymentPluginConf::whereKey($model->id)->lockForUpdate()->first() : null;
            if ($model->plugin_code === AlipayStoreReceiptRules::PLUGIN) $model->config = AlipayStoreReceiptRules::config((array) $model->config);
            if ($current && (AlipayStoreReceiptRules::supported((string) $current->plugin_code) || AlipayStoreReceiptRules::supported((string) $model->plugin_code))) {
                $old = (array) $current->config; $new = (array) $model->config;
                $changed = $current->plugin_code !== $model->plugin_code;
                foreach (['app_id', 'bill_user_id', 'store_no', 'receipt_match_mode', 'receipt_qrcode_content', 'receipt_qrcode_image'] as $key) {
                    if (($old[$key] ?? '') !== ($new[$key] ?? '')) $changed = true;
                }
                if ($changed && self::hasPending(self::channelIds((int) $current->id))) AlipayStoreReceiptRules::fail('存在未结束支付单，不能修改收款身份、门店、模式或二维码');
            }
            $result = $save();
            self::topology();
            return $result;
        });
    }

    public static function saveChannel(PaymentChannel $model, callable $save): mixed
    {
        return self::atomic(function () use ($model, $save) {
            $old = $model->exists ? PaymentChannel::whereKey($model->id)->lockForUpdate()->first() : null;
            if ($old && self::hasPending([(int) $old->id])) {
                foreach (['api_config_id', 'plugin_code', 'pay_type_id'] as $key) {
                    if ((string) $old->$key !== (string) $model->$key) AlipayStoreReceiptRules::fail('通道存在未结束支付单，不能更换插件、配置或支付方式');
                }
            }
            $result = $save();
            self::topology();
            return $result;
        });
    }

    public static function deleteConfig(PaymentPluginConf $model, callable $delete): mixed
    {
        return self::atomic(function () use ($model, $delete) {
            if (self::channelIds((int) $model->id) !== []) AlipayStoreReceiptRules::fail('支付宝收款配置仍被通道引用，不能删除');
            return $delete();
        });
    }

    public static function deleteChannel(PaymentChannel $model, callable $delete): mixed
    {
        return self::atomic(function () use ($model, $delete) {
            // Keep historical scope/reference evidence, not just pending orders.
            if (PayOrder::where('channel_id', $model->id)->exists()) AlipayStoreReceiptRules::fail('支付宝收款通道存在支付记录，请停用而不是删除');
            return $delete();
        });
    }

    /** A permanent reservation; it is NOT proof of payment until the payment transaction commits. */
    public static function claim(string $uid, string $tradeNo, string $payNo, string $storeNo, int $amount, int $paidAt): void
    {
        $prior = Db::table('ma_alipay_receipt_flow')->where('bill_user_id', $uid)->where('trade_no', $tradeNo)->lockForUpdate()->first();
        if ($prior) {
            if ($prior->pay_no !== $payNo || $prior->store_no !== $storeNo || (int) $prior->receipt_amount !== $amount || (int) $prior->paid_at !== $paidAt) {
                AlipayStoreReceiptRules::fail('支付宝流水已绑定其他订单或内容发生冲突');
            }
            return;
        }
        if (Db::table('ma_alipay_receipt_flow')->where('pay_no', $payNo)->exists()) AlipayStoreReceiptRules::fail('支付单已绑定另一笔支付宝流水');
        // Preserve pre-upgrade history too. Never transfer an existing channel reference to a new order.
        if (PayOrder::where('pay_no', '<>', $payNo)->where(function ($q) use ($tradeNo) { $q->where('channel_order_no', $tradeNo)->orWhere('channel_trade_no', $tradeNo); })->exists()) {
            AlipayStoreReceiptRules::fail('支付宝流水已存在于历史支付单');
        }
        Db::table('ma_alipay_receipt_flow')->insert([
            'bill_user_id' => $uid, 'trade_no' => $tradeNo, 'pay_no' => $payNo, 'store_no' => $storeNo,
            'receipt_amount' => $amount, 'paid_at' => $paidAt, 'created_at' => AlipayStoreReceiptRules::date(time()),
        ]);
    }
}
