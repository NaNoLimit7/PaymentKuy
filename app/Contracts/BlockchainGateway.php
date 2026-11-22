<?php

namespace App\Contracts;

use App\DTOs\ReceiptResult;
use App\Models\BlockchainReceipt;

interface BlockchainGateway
{
    /**
     * Check if the receipt already exists on-chain.
     * Maps to contract view function: hasReceipt(orderId)
     */
    public function hasReceipt(string $contractOrderId): bool;

    /**
     * Record the receipt on-chain.
     * Maps to contract tx: recordReceipt(orderId, receiptHash, amount, settlementTime, gatewayRef, currency)
     */
    public function recordReceipt(BlockchainReceipt $receipt): ReceiptResult;

    /**
     * Verify the parameters match the on-chain receipt hash.
     * Maps to contract view function: verifyReceipt(orderId, amount, settlementTime, gatewayRef)
     */
    public function verifyReceipt(BlockchainReceipt $receipt): bool;
}
