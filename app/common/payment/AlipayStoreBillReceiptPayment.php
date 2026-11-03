<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\model\payment\PayOrder;
use app\model\payment\PaymentPluginConf;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\service\payment\receipt\AlipayReceiptGuard as Guard;
use app\service\payment\receipt\AlipayStoreReceiptAllocationService;
use app\service\payment\receipt\AlipayStoreReceiptRules as Rules;
use support\Db;
use support\Request;

/** Amount-only store receipt plugin. The queue, not an unsigned public HTTP callback, supplies evidence. */
class AlipayStoreBillReceiptPayment extends AlipayBillReceiptPayment
{
    public function __construct(PayOrderRepository $orders, PaymentChannelRepository $channels, PaymentTypeRepository $types)
    {
        parent::__construct($orders, $channels, $types);
        $this->paymentInfo['code'] = Rules::PLUGIN;
        $this->paymentInfo['name'] = '支付宝门店账单收款';
        $this->paymentInfo['version'] = '1.0.0';
        $schema = [];
        foreach ($this->paymentInfo['config_schema'] as $field) {
            if ($field['field'] === 'receipt_match_mode') continue;
            $schema[] = $field;
            if ($field['field'] === 'bill_user_id') {
                $schema[] = ['type' => 'input', 'field' => 'store_no', 'title' => '门店编号', 'value' => '',
                    'props' => ['placeholder' => '完整编号，例如：店铺1793551172076'],
                    'validate' => [['required' => true, 'message' => '门店编号不能为空']]];
            }
        }
        $this->paymentInfo['config_schema'] = $schema;
    }

    public function init(array $channelConfig): void
    {
        parent::init(Rules::config($channelConfig));
    }

    public function pay(array $order): array
    {
        try {
            return $this->prepareAndPresent($order);
        } catch (\app\exception\PaymentException $e) {
            // All business rejections here precede presentation and perform no upstream payment call.
            throw new \app\exception\PaymentDefinitiveException($e->getMessage(), 40200, $e->getData());
        }
    }

    private function prepareAndPresent(array $order): array
    {
        $payNo = (string) ($order['pay_no'] ?? '');
        $meta = (new AlipayStoreReceiptAllocationService($this->payOrderRepository))
            ->prepareForPresentation($payNo, $this->channelConfig);
        $params = ['_page' => 'receiptQrcode', 'amount' => FormatHelper::amount((int) $meta['receipt_amount']),
            'original_amount' => FormatHelper::amount((int) $meta['original_amount']), 'receipt_match_mode' => 'amount',
            'receipt_valid_seconds' => $this->receiptValidSeconds(), 'expire_at' => $meta['expire_at'],
            'expire_at_timestamp' => Rules::timestamp($meta['expire_at']), 'server_time_timestamp' => time(),
            'description' => '请使用支付宝扫描二维码，并严格按照页面显示的金额付款。'];
        foreach (['qrcode', 'qrcode_image'] as $key) if ($meta[$key] !== '') $params[$key] = $meta[$key];
        return $this->pendingPaymentResult($order, $this->payResult($params, $payNo, 'alipay'));
    }

    private function assertLiveConfig(): void
    {
        $conf = PaymentPluginConf::whereKey((int) $this->getConfig('api_config_id'))->lockForUpdate()->first();
        if (!$conf || $conf->plugin_code !== Rules::PLUGIN) Rules::fail('门店账单配置不存在或已更换');
        $live = Rules::config((array) $conf->config);
        foreach (['app_id', 'bill_user_id', 'store_no', 'receipt_match_mode', 'receipt_qrcode_content', 'receipt_qrcode_image'] as $key) {
            if (($live[$key] ?? '') !== ($this->channelConfig[$key] ?? '')) Rules::fail('门店账单配置已变化，请重试');
        }
    }

    public function notify(Request $request): array
    {
        Rules::fail('门店账单仅接受受信任监听队列，不接受未签名 HTTP 付款通知');
    }

    public function channelNotifyPayload(array $payload): array
    {
        return ['pay_no' => (string) $this->locateStoreOrder($payload)->pay_no];
    }

    private function locateStoreOrder(array $payload): PayOrder
    {
        $record = (array) ($payload['record'] ?? []);
        $flow = Rules::flow($record, $this->channelConfig, time());
        $bound = Db::table('ma_alipay_receipt_flow')->where('bill_user_id', $this->getConfig('bill_user_id'))->where('trade_no', $flow['trade_no'])->first();
        if ($bound) {
            if ($bound->pay_no !== $record['candidate_pay_no']) Rules::fail('流水不能改配给另一笔订单');
            $row = $this->payOrderRepository->findByPayNo($bound->pay_no);
            if (!$row) Rules::fail('流水已绑定的支付单不存在');
            $this->validateOrder($row, $record, $flow, true);
            return $row;
        }
        $rows = $this->payOrderRepository->listMutableReceiptOrdersByAmount($this->receiptChannelIds(), $flow['amount'], 0, Rules::date(time()));
        $matches = $rows->filter(function ($row) use ($flow) {
            $meta = (array) (((array) $row->ext_json)['personal_receipt'] ?? []);
            return Rules::metadataMatches($meta, $this->channelConfig, $flow);
        })->values();
        if ($matches->count() !== 1) Rules::fail('支付宝门店流水未找到唯一有效支付单');
        $row = $matches->first();
        $this->validateOrder($row, $record, $flow, false);
        return $row;
    }

    private function validateOrder(PayOrder $row, array $record, array $flow, bool $bound): void
    {
        $meta = (array) (((array) $row->ext_json)['personal_receipt'] ?? []);
        if (!in_array((int) $row->channel_id, $this->receiptChannelIds(), true) || (int) $row->channel_id !== $record['candidate_channel_id'] ||
            (string) $row->pay_no !== $record['candidate_pay_no'] || !Rules::metadataMatches($meta, $this->channelConfig, $flow)) Rules::fail('门店流水与支付单快照不一致');
        if ((string) $row->request_at !== ($meta['request_at'] ?? '') || (string) $row->expire_at !== ($meta['expire_at'] ?? '')) Rules::fail('支付单识别窗口已变化');
        if ($bound && (int) $row->status === TradeConstant::ORDER_STATUS_SUCCESS) return;
        if (!in_array((int) $row->status, TradeConstant::orderMutableStatuses(), true) || Rules::timestamp((string) $row->expire_at) <= time()) Rules::fail('门店支付单已结束或过期，需人工核对');
    }

    public function notifyPayload(array $payload): array
    {
        // The callback service holds the guard until ledger, balance, payment and callback log commit.
        return Guard::atomic(function () use ($payload) {
            $this->assertLiveConfig();
            $row = $this->locateStoreOrder($payload);
            $record = (array) $payload['record'];
            $flow = Rules::flow($record, $this->channelConfig, time());
            $row = $this->lockedPayOrder((string) $row->pay_no);
            $this->validateOrder($row, $record, $flow, true);
            Guard::claim((string) $this->getConfig('bill_user_id'), $flow['trade_no'], (string) $row->pay_no, (string) $this->getConfig('store_no'), $flow['amount'], $flow['paid_at']);
            $original = $this->restoreOriginalPayAmount((string) $row->pay_no, $record, $flow['trade_no'], $flow['amount']);
            return ['status' => PaymentPluginStatusConstant::SUCCESS, 'pay_no' => (string) $row->pay_no, 'paid_amount' => $original,
                'message' => 'receipt_watcher 已确认支付宝门店账单流水', 'chan_order_no' => $flow['trade_no'], 'chan_trade_no' => $flow['trade_no'],
                'channel_status' => 'receipt_watcher_received', 'paid_at' => Rules::date($flow['paid_at'])];
        });
    }
}
