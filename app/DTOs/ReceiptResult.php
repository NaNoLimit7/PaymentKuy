<?php

namespace App\DTOs;

readonly class ReceiptResult
{
    public function __construct(
        public string $txHash,
        public int $blockNumber,
        public string $status = 'confirmed',
    ) {}
}
