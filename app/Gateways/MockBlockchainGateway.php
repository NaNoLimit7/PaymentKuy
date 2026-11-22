<?php

namespace App\Gateways;

use App\Contracts\BlockchainGateway;
use App\DTOs\ReceiptResult;
use App\Exceptions\BlockchainGatewayException;
use App\Models\BlockchainReceipt;

class MockBlockchainGateway implements BlockchainGateway
{
    /**
     * In-memory storage of recorded mock receipts.
     *
     * @var array<string, array{receiptHash: string, txHash: string, blockNumber: int}>
     */
    protected static array $mockStore = [];

    public static function reset(): void
    {
        self::$mockStore = [];
    }

    public function hasReceipt(string $contractOrderId): bool
    {
        return isset(self::$mockStore[$contractOrderId]);
    }

    public function recordReceipt(BlockchainReceipt $receipt): ReceiptResult
    {
        $orderId = $receipt->contract_order_id;

        if ($this->hasReceipt($orderId)) {
            throw new BlockchainGatewayException(
                "Order ID {$orderId} already recorded on-chain",
                'ALREADY_RECORDED',
                null,
                409
            );
        }

        $txHash = '0x'.hash('sha256', $orderId.microtime(true));
        $blockNumber = 12094000 + count(self::$mockStore);

        self::$mockStore[$orderId] = [
            'receiptHash' => $receipt->receipt_hash,
            'txHash' => $txHash,
            'blockNumber' => $blockNumber,
        ];

        return new ReceiptResult(
            txHash: $txHash,
            blockNumber: $blockNumber,
            status: 'confirmed'
        );
    }

    public function verifyReceipt(BlockchainReceipt $receipt): bool
    {
        $orderId = $receipt->contract_order_id;

        if (! isset(self::$mockStore[$orderId])) {
            return false;
        }

        return hash_equals(self::$mockStore[$orderId]['receiptHash'], $receipt->receipt_hash);
    }
}
