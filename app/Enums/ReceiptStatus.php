<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Failed], true);
    }
}
