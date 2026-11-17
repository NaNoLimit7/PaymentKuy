<?php

namespace App\Services;

use App\Enums\ReceiptStatus;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\BlockchainReceipt;
use App\Models\Transaction;
use RuntimeException;

class BlockchainReceiptService
{
    public function __construct(private readonly ReceiptHasher $hasher) {}

    public function createForProceed(Transaction $transaction, AuditLog $auditLog): BlockchainReceipt
    {
        if ($transaction->status !== TransactionStatus::Proceed) {
            throw new RuntimeException('Only proceeding transactions can be recorded on-chain.');
        }

        if ($transaction->paid_at === null || $transaction->gateway_ref === null || $transaction->amount_minor === null) {
            throw new RuntimeException('Transaction is missing settlement data.');
        }

        $receiptHash = $this->hasher->compute(
            $transaction->reference,
            (string) $transaction->amount_minor,
            $transaction->paid_at->timestamp,
            $transaction->gateway_ref,
        );

        return BlockchainReceipt::create([
            'transaction_id' => $transaction->getKey(),
            'audit_log_id' => $auditLog->getKey(),
            'contract_order_id' => $transaction->reference,
            'receipt_hash' => $receiptHash,
            'amount_minor' => $transaction->amount_minor,
            'settlement_time' => $transaction->paid_at->timestamp,
            'gateway_ref' => $transaction->gateway_ref,
            'currency' => $transaction->currency,
            'network' => (string) config('payment.receipt.network', 'sepolia'),
            'contract_address' => config('payment.receipt.contract_address'),
            'status' => ReceiptStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);
    }
}
