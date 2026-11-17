<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Proceed = 'proceed';
    case Expired = 'expired';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
