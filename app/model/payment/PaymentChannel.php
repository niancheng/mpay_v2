<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付通道模型。
 * 承载通道成本、分成、限额、启停状态和通道模式。
 */
class PaymentChannel extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_payment_channel';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'merchant_id',
        'name',
        'split_rate_bp',
        'cost_rate_bp',
        'channel_mode',
        'pay_type_id',
        'plugin_code',
        'api_config_id',
        'daily_limit_amount',
        'daily_limit_count',
        'min_amount',
        'max_amount',
        'remark',
        'status',
        'sort_no',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'split_rate_bp' => 'integer',
        'cost_rate_bp' => 'integer',
        'channel_mode' => 'integer',
        'pay_type_id' => 'integer',
        'api_config_id' => 'integer',
        'daily_limit_amount' => 'integer',
        'daily_limit_count' => 'integer',
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'status' => 'integer',
        'sort_no' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Guard all model writes, including merchant configuration and plugin synchronization. */
    public function save(array $options = [])
    {
        $rules = \app\service\payment\receipt\AlipayStoreReceiptRules::class;
        if (!$rules::supported((string) $this->plugin_code) && !$rules::supported((string) $this->getOriginal('plugin_code'))) return parent::save($options);
        return \app\service\payment\receipt\AlipayReceiptGuard::saveChannel($this, fn () => parent::save($options));
    }

    public function delete()
    {
        if (!\app\service\payment\receipt\AlipayStoreReceiptRules::supported((string) $this->plugin_code)) return parent::delete();
        return \app\service\payment\receipt\AlipayReceiptGuard::deleteChannel($this, fn () => parent::delete());
    }
}




