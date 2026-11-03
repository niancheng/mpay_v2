<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\PaymentPluginStatusConstant as PluginStatus;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ConflictException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use support\Log;

/**
 * 已鉴权支付请求的只读复用。这里不选路、不分配金额、不调用插件、不推进资金或订单状态。
 * null 仅表示尚无支付单，或原尝试已明确失败；是否允许失败重试仍由发起服务裁决。
 */
class PayOrderReuseService extends BaseService
{
    public function __construct(
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository
    ) {}

    public function findReusable(array $input, Merchant $merchant, ?int $channelId = null): ?array
    {
        return $this->transactionRetry(function () use ($input, $merchant, $channelId) {
            $bizOrder = $this->bizOrderRepository->findForUpdateByMerchantAndOrderNo(
                (int) $input['merchant_id'], trim((string) $input['merchant_order_no'])
            );
            return $bizOrder ? $this->resolveLocked($bizOrder, $input, $merchant, $channelId) : null;
        });
    }

    /** 调用方已锁定业务单；创建路径必须先取得原有门店 guard，再进入此方法。 */
    public function resolveLocked(BizOrder $bizOrder, array $input, Merchant $merchant, ?int $channelId = null): ?array
    {
        $this->assertBusinessMatches($bizOrder, $input);
        if ((int) $merchant->id !== (int) $bizOrder->merchant_id) {
            throw new ConflictException('商户订单归属不一致');
        }

        $activePayNo = trim((string) ($bizOrder->active_pay_no ?? ''));
        $bizStatus = (int) $bizOrder->status;
        $payOrder = null;
        if ($activePayNo !== '') {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($activePayNo);
            if (!$payOrder) $this->invalidReference($bizOrder);
        } elseif (TradeConstant::isOrderTerminalStatus($bizStatus)) {
            // 生命周期在成功/失败/关闭/超时后清空 active_pay_no，终态必须查历史单。
            $query = $this->payOrderRepository->query()->where('biz_no', (string) $bizOrder->biz_no);
            if ($bizStatus === TradeConstant::ORDER_STATUS_SUCCESS) {
                // 晚到成功可能来自较早尝试，不能把“最新支付单”当成成功支付单。
                $query->where('status', TradeConstant::ORDER_STATUS_SUCCESS)->orderBy('paid_at')->orderBy('id');
            } else {
                $query->orderByDesc('attempt_no')->orderByDesc('id');
            }
            $payOrder = $query->lockForUpdate()->first();
        }

        if (!$payOrder) {
            if ((int) $bizOrder->attempt_count > 0 || $bizStatus === TradeConstant::ORDER_STATUS_PAYING) {
                $this->invalidReference($bizOrder);
            }
            if ($bizStatus !== TradeConstant::ORDER_STATUS_CREATED || self::hasExpired($bizOrder->expire_at)) {
                throw new BusinessStateException('订单已结束或已超时，不能重新发起支付', ['biz_no' => (string) $bizOrder->biz_no]);
            }
            return null;
        }
        if ((string) $payOrder->biz_no !== (string) $bizOrder->biz_no
            || (int) $payOrder->merchant_id !== (int) $bizOrder->merchant_id) {
            $this->invalidReference($bizOrder);
        }

        // 只有双方都已明确失败才交回原重试策略；结果未知、关闭、超时均不能重建。
        if ($bizStatus === TradeConstant::ORDER_STATUS_FAILED
            && (int) $payOrder->status === TradeConstant::ORDER_STATUS_FAILED
            && !self::hasExpired($bizOrder->expire_at)
            && !self::hasExpired($payOrder->expire_at)) {
            return null;
        }

        $this->assertPaymentMatches($payOrder, $input, $channelId);
        $result = $this->buildResult($bizOrder, $payOrder, $merchant);
        Log::info('重复支付请求已恢复原支付单', [
            'merchant_id' => (int) $bizOrder->merchant_id,
            'biz_no' => (string) $bizOrder->biz_no,
            'pay_no' => (string) $payOrder->pay_no,
            'status' => (int) $payOrder->status,
        ]);
        return $result;
    }

    /** 金额始终比较业务单原始金额，而非门店插件分配后的实付金额。 */
    public function assertBusinessMatches(BizOrder $bizOrder, array $input): void
    {
        if ((int) $bizOrder->merchant_id !== (int) $input['merchant_id']
            || (string) $bizOrder->merchant_order_no !== trim((string) $input['merchant_order_no'])) {
            throw new ConflictException('商户订单归属不一致');
        }
        if ((int) $bizOrder->order_amount !== (int) $input['pay_amount']) {
            throw new ValidationException('同一商户订单号金额不一致', [
                'merchant_id' => (int) $input['merchant_id'],
                'merchant_order_no' => trim((string) $input['merchant_order_no']),
            ]);
        }
        foreach (['subject', 'body', 'notify_url', 'return_url'] as $field) {
            if (trim((string) ($bizOrder->{$field} ?? '')) !== trim((string) ($input[$field] ?? ''))) {
                // 不附带 pay_no：协议入口不能把参数冲突异常当成合法恢复跳转。
                throw new ConflictException('商户订单信息不一致', ['biz_no' => (string) $bizOrder->biz_no, 'field' => $field]);
            }
        }
        if ($this->businessExt((array) ($bizOrder->ext_json ?? []))
            !== $this->businessExt((array) ($input['ext_json'] ?? []))) {
            throw new ConflictException('商户订单扩展信息不一致', ['biz_no' => (string) $bizOrder->biz_no]);
        }
        // client_ip / device 仅是访问环境：允许变化，但绝不覆盖原订单快照。
    }

    private function businessExt(array $ext): array
    {
        // payment 单独校验；presentation 和提交入口不是商户业务参数。
        // 协议版本保留，以免悄悄改变已有订单的通知签名协议。
        unset($ext['payment'], $ext['presentation'], $ext['_submit_type']);
        return $this->canonicalArray($ext);
    }

    private function canonicalArray(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalArray($item);
        }
        return $value;
    }

    private function assertPaymentMatches(PayOrder $payOrder, array $input, ?int $channelId): void
    {
        if (isset($input['pay_type_id']) && (int) $input['pay_type_id'] !== (int) $payOrder->pay_type_id) {
            throw new ConflictException('当前订单已有其他支付方式的支付单，请继续原支付单');
        }
        $channelId ??= (int) ($input['channel_id'] ?? 0) > 0 ? (int) $input['channel_id'] : null;
        if ($channelId !== null && $channelId !== (int) $payOrder->channel_id) {
            throw new ConflictException('当前订单已有其他通道的支付单，请继续原支付单');
        }
        $stored = (array) ($payOrder->ext_json['payment'] ?? []);
        foreach ((array) ($input['ext_json']['payment'] ?? []) as $field => $value) {
            if ($value === null || $value === '') continue;
            $previous = $stored[$field] ?? ($field === 'method' ? 'web' : '');
            if (!is_scalar($value) || !is_scalar($previous) || (string) $value !== (string) $previous) {
                // 只报告字段名，不把付款码、openid 等身份信息写进错误或日志。
                throw new ConflictException('原支付单的支付产品或付款身份不一致', ['field' => $field]);
            }
        }
    }

    public function buildResult(BizOrder $bizOrder, PayOrder $payOrder, Merchant $merchant): array
    {
        $paymentPageUrl = rtrim((string) sys_config('site_url'), '/') . '/payment/' . rawurlencode((string) $payOrder->pay_no);
        $presentation = (array) ($payOrder->ext_json['presentation'] ?? []);
        if (!self::canPresent($payOrder, $bizOrder) || !self::hasReadyPresentation($presentation)) {
            // V1 / V2 共用合法 jump 结果；V2 不再因快照尚未写入而报“缺少承接信息”。
            // 这是响应 DTO，不写回原快照，避免本站页面跳回自身。
            $presentation = ['pay_page' => 'jump', 'pay_params' => ['url' => $paymentPageUrl]];
        }
        $status = match ((int) $payOrder->status) {
            TradeConstant::ORDER_STATUS_SUCCESS => PluginStatus::SUCCESS,
            TradeConstant::ORDER_STATUS_FAILED => PluginStatus::FAILED,
            TradeConstant::ORDER_STATUS_CLOSED, TradeConstant::ORDER_STATUS_TIMEOUT => PluginStatus::CLOSED,
            TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING => PluginStatus::PENDING,
            default => PluginStatus::UNKNOWN,
        };
        $paymentResult = [
            'status' => $status,
            'paid_amount' => $status === PluginStatus::SUCCESS ? (int) $payOrder->pay_amount : null,
            'chan_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'chan_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
            'presentation' => $presentation,
        ];
        return [
            'created' => false,
            'reused' => true,
            'merchant' => $merchant,
            'biz_order' => $bizOrder,
            'pay_order' => $payOrder,
            'route' => [],
            'payment_result' => $paymentResult,
            'pay_params' => (array) ($presentation['pay_params'] ?? []),
            'payment_page_url' => $paymentPageUrl,
        ];
    }

    /** 展示资格只读判断；截止时间到达不在此伪造支付终态或释放金额。 */
    public static function canPresent(PayOrder $payOrder, ?BizOrder $bizOrder = null): bool
    {
        if (!in_array((int) $payOrder->status, TradeConstant::orderMutableStatuses(), true)
            || self::hasExpired($payOrder->expire_at)) return false;
        if ($bizOrder !== null && (
            !in_array((int) $bizOrder->status, TradeConstant::orderMutableStatuses(), true)
            || self::hasExpired($bizOrder->expire_at)
            || (string) $bizOrder->active_pay_no !== (string) $payOrder->pay_no
        )) return false;
        return true;
    }

    public static function hasReadyPresentation(array $presentation): bool
    {
        $page = (string) ($presentation['pay_page'] ?? '');
        if (!in_array($page, ['qrcode', 'jump', 'urlscheme', 'html', 'page', 'jsapi'], true)) return false;
        return $page !== 'page' || ($presentation['pay_params']['_page'] ?? '') !== 'paymentPending';
    }

    public static function hasExpired(mixed $value): bool
    {
        if ($value === null || $value === '') return false;
        $timestamp = $value instanceof DateTimeInterface ? $value->getTimestamp() : strtotime((string) $value);
        return $timestamp === false || $timestamp <= time();
    }

    /** 只接纳业务单商户订单唯一键的首次插入竞争，不吞掉其他 SQL 错误。 */
    public static function isMerchantOrderRace(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && preg_match('/(?:[.\x27`])uk_merchant_order[\x27`]/', (string) ($e->errorInfo[2] ?? '')) === 1;
    }

    private function invalidReference(BizOrder $bizOrder): never
    {
        Log::error('支付订单引用异常，已阻止重复创建', ['biz_no' => (string) $bizOrder->biz_no]);
        throw new BusinessStateException('原支付单数据异常，请联系商户核实', ['biz_no' => (string) $bizOrder->biz_no]);
    }
}
