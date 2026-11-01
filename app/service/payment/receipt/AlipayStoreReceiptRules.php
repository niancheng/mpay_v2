<?php

declare(strict_types=1);

namespace app\service\payment\receipt;

use app\exception\PaymentException;
use DateTimeImmutable;
use DateTimeZone;

/** Pure validation shared by configuration, allocation and final receipt matching. */
final class AlipayStoreReceiptRules
{
    public const PLUGIN = 'alipay_store_bill_receipt';
    public const LEGACY = 'alipay_bill_receipt';
    public const METHOD = 'alipay.data.bill.sell.query';

    public static function supported(string $code): bool
    {
        return in_array($code, [self::PLUGIN, self::LEGACY], true);
    }

    public static function fail(string $message): never
    {
        throw new PaymentException($message, 40200);
    }

    public static function config(array $config): array
    {
        foreach (['app_id', 'bill_user_id', 'private_key', 'alipay_public_key', 'store_no'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                self::fail('支付宝门店账单配置缺少必填参数：' . $key);
            }
            $config[$key] = trim($config[$key]);
        }
        if (!preg_match('/^2088[0-9]{12}$/D', $config['bill_user_id']) || !preg_match('/^[0-9]{10,32}$/D', $config['app_id'])) {
            self::fail('支付宝收款 UID 或 AppID 格式不正确');
        }
        if (!preg_match('//u', $config['store_no']) || preg_match('/[\x00-\x1f\x7f]/', $config['store_no']) || preg_match_all('/./us', $config['store_no']) > 128) {
            self::fail('门店编号格式不正确，必须保留完整编号且不超过 128 字符');
        }
        if (($config['receipt_match_mode'] ?? 'amount') !== 'amount') {
            self::fail('支付宝门店账单仅支持金额匹配，禁止付款备注和免输转账');
        }
        $config['receipt_match_mode'] = 'amount';
        foreach (['receipt_valid_seconds' => [300, 60, 1800], 'amount_offset_max' => [99, 0, 99], 'receipt_watcher_query_interval_seconds' => [3, 2, 60]] as $key => [$default, $min, $max]) {
            $value = $config[$key] ?? $default;
            if ((!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D', $value))) || (int) $value < $min || (int) $value > $max) {
                self::fail('支付宝门店账单配置超出范围：' . $key);
            }
            $config[$key] = (int) $value;
        }
        if (trim((string) ($config['receipt_qrcode_content'] ?? '')) === '' && trim((string) ($config['receipt_qrcode_image'] ?? '')) === '') {
            self::fail('请配置该门店的收款码内容或图片');
        }
        return $config;
    }

    public static function cents(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            self::fail('支付宝门店流水金额格式不正确');
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function timestamp(mixed $value): int
    {
        if (!is_string($value)) self::fail('支付宝门店订单时间缺失');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) self::fail('支付宝门店订单时间格式不正确');
        return $date->getTimestamp();
    }

    public static function date(int $value): string
    {
        return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    }

    public static function flow(array $record, array $config, int $now): array
    {
        if (($record['source_method'] ?? '') !== self::METHOD || ($record['pay_type'] ?? '') !== 'alipay' ||
            ($record['bill_user_id'] ?? '') !== $config['bill_user_id'] || ($record['store_no'] ?? '') !== $config['store_no']) {
            self::fail('支付宝门店流水来源、账号或门店不匹配');
        }
        if (($record['trade_status'] ?? '') !== '成功') self::fail('支付宝门店交易尚未成功');
        if (!is_string($record['order_no'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $record['order_no'])) self::fail('支付宝交易号不正确');
        if (!is_string($record['candidate_pay_no'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $record['candidate_pay_no'])) self::fail('门店流水缺少候选支付单');
        if (!is_int($record['candidate_channel_id'] ?? null) || $record['candidate_channel_id'] <= 0) self::fail('门店流水缺少候选通道');
        $amount = self::cents($record['price'] ?? null);
        if ($amount <= 0 || self::cents($record['refund_amount'] ?? null) !== 0 || self::cents($record['net_mdiscount'] ?? null) !== 0) self::fail('退款、优惠或非正金额交易需要人工核对');
        if (self::cents($record['service_fee'] ?? '0.00') > $amount) self::fail('交易手续费异常');
        if (!is_int($record['paid_at'] ?? null) || !is_int($record['created_at'] ?? null) || $record['created_at'] <= 0 ||
            $record['created_at'] > $record['paid_at'] || $record['paid_at'] > $now + 5) self::fail('支付宝门店流水时间不正确');
        return ['amount' => $amount, 'paid_at' => $record['paid_at'], 'created_at' => $record['created_at'], 'trade_no' => $record['order_no']];
    }

    public static function metadataMatches(array $meta, array $config, array $flow): bool
    {
        return ($meta['platform'] ?? '') === self::PLUGIN && ($meta['mode'] ?? '') === 'amount' &&
            ($meta['bill_user_id'] ?? '') === $config['bill_user_id'] && ($meta['store_no'] ?? '') === $config['store_no'] &&
            ($meta['receipt_amount'] ?? 0) === $flow['amount'] &&
            self::timestamp($meta['request_at'] ?? null) <= $flow['created_at'] &&
            self::timestamp($meta['expire_at'] ?? null) >= $flow['paid_at'];
    }

    public static function allocate(int $original, array $used, int $maxOffset): int
    {
        $occupied = array_fill_keys($used, true);
        for ($offset = 0; $offset <= $maxOffset; ++$offset) {
            if (!isset($occupied[$original + $offset])) return $original + $offset;
        }
        self::fail('当前门店该金额已被占用，可用金额偏移已用尽');
    }
}
