<?php

declare(strict_types=1);

namespace app\exception;

/** Only a pre-dispatch, rolled-back reservation may trigger another store attempt. */
class ReceiptAmountOccupiedException extends PaymentDefinitiveException
{
    public function __construct(string $message = '当前可用门店的该金额均被占用，请稍后重试', array $data = [])
    {
        parent::__construct($message, 40200, array_replace($data, ['reason_code' => 'receipt_amount_occupied']));
    }
}
