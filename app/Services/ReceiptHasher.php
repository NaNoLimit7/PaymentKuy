<?php

namespace App\Services;

use InvalidArgumentException;
use kornrunner\Keccak;

class ReceiptHasher
{
    public function compute(string $orderId, string $amountMinor, int $settlementTime, string $gatewayRef): string
    {
        $packed = $orderId
            .$this->uint256($amountMinor)
            .$this->uint256((string) $settlementTime)
            .$gatewayRef;

        return '0x'.Keccak::hash($packed, 256);
    }

    private function uint256(string $value): string
    {
        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException('Value must be a non-negative integer.');
        }

        $hex = '';

        while (bccomp($value, '0') > 0) {
            $hex = dechex((int) bcmod($value, '16')).$hex;
            $value = bcdiv($value, '16', 0);
        }

        return hex2bin(str_pad($hex === '' ? '0' : $hex, 64, '0', STR_PAD_LEFT));
    }
}
