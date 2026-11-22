<?php

namespace App\Services;

use App\Contracts\BlockchainGateway;
use App\Enums\ReceiptStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\BlockchainGatewayException;
use App\Models\AuditLog;
use App\Models\BlockchainReceipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
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

    /**
     * Process a single BlockchainReceipt through the provided BlockchainGateway.
     * Implements idempotency, retry backoff, and terminal status handling (PRD 4.4 & 6.5).
     */
    public function processReceipt(BlockchainReceipt $receipt, BlockchainGateway $gateway): bool
    {
        // 1. Check if already recorded on-chain (Idempotency safety)
        try {
            if ($gateway->hasReceipt($receipt->contract_order_id)) {
                $isValid = $gateway->verifyReceipt($receipt);

                if ($isValid) {
                    $receipt->update([
                        'status' => ReceiptStatus::Confirmed,
                        'confirmed_at' => $receipt->confirmed_at ?? now(),
                        'last_error' => null,
                    ]);
                    Log::info('BlockchainReceiptService: Already confirmed on-chain', [
                        'order_id' => $receipt->contract_order_id,
                    ]);

                    return true;
                }

                $receipt->update([
                    'status' => ReceiptStatus::Failed,
                    'last_error' => 'On-chain receipt hash mismatch with local receipt',
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('BlockchainReceiptService: Error during hasReceipt pre-check', [
                'order_id' => $receipt->contract_order_id,
                'exception' => $e->getMessage(),
            ]);
        }

        // 2. Submit transaction to Blockchain
        $maxAttempts = (int) config('payment.blockchain.max_attempts', 5);

        try {
            $result = $gateway->recordReceipt($receipt);

            $receipt->update([
                'tx_hash' => $result->txHash,
                'block_number' => $result->blockNumber,
                'status' => ReceiptStatus::Confirmed,
                'submitted_at' => $receipt->submitted_at ?? now(),
                'confirmed_at' => now(),
                'last_error' => null,
            ]);

            Log::info('BlockchainReceiptService: Receipt recorded successfully', [
                'order_id' => $receipt->contract_order_id,
                'tx_hash' => $result->txHash,
                'block_number' => $result->blockNumber,
            ]);

            return true;
        } catch (BlockchainGatewayException $e) {
            $attempts = $receipt->attempts + 1;
            $code = $e->errorCode;

            Log::error('BlockchainReceiptService: Gateway exception', [
                'order_id' => $receipt->contract_order_id,
                'error_code' => $code,
                'message' => $e->getMessage(),
                'attempt' => $attempts,
            ]);

            // If already recorded on-chain, re-verify
            if ($code === 'ALREADY_RECORDED') {
                if ($gateway->verifyReceipt($receipt)) {
                    $receipt->update([
                        'status' => ReceiptStatus::Confirmed,
                        'confirmed_at' => now(),
                        'last_error' => null,
                    ]);

                    return true;
                }
                $receipt->update([
                    'status' => ReceiptStatus::Failed,
                    'last_error' => 'Already recorded with mismatched hash',
                    'attempts' => $attempts,
                ]);

                return false;
            }

            // Non-retryable validation errors (422)
            if (in_array($code, ['HASH_MISMATCH', 'INVALID_INPUT', 'CONTRACT_REVERT'], true)) {
                $receipt->update([
                    'status' => ReceiptStatus::Failed,
                    'last_error' => $e->getMessage(),
                    'attempts' => $attempts,
                ]);

                return false;
            }

            // Retryable errors (504 timeout, 502 RPC error)
            $newStatus = $receipt->status;
            if ($e->txHash) {
                $newStatus = ReceiptStatus::Submitted;
            }

            if ($attempts >= $maxAttempts) {
                $receipt->update([
                    'status' => ReceiptStatus::Failed,
                    'last_error' => "Max attempts reached ({$attempts}/{$maxAttempts}): ".$e->getMessage(),
                    'attempts' => $attempts,
                    'tx_hash' => $e->txHash ?? $receipt->tx_hash,
                ]);

                return false;
            }

            // Exponential backoff delay (seconds)
            $backoffSeconds = (int) (pow(2, $attempts) * 30); // 60s, 120s, 240s...
            $receipt->update([
                'status' => $newStatus,
                'attempts' => $attempts,
                'last_error' => $e->getMessage(),
                'next_attempt_at' => now()->addSeconds($backoffSeconds),
                'tx_hash' => $e->txHash ?? $receipt->tx_hash,
            ]);

            return false;
        } catch (\Throwable $e) {
            $attempts = $receipt->attempts + 1;
            Log::error('BlockchainReceiptService: Unexpected error', [
                'order_id' => $receipt->contract_order_id,
                'exception' => $e->getMessage(),
            ]);

            $receipt->update([
                'status' => $attempts >= $maxAttempts ? ReceiptStatus::Failed : $receipt->status,
                'attempts' => $attempts,
                'last_error' => $e->getMessage(),
                'next_attempt_at' => now()->addMinutes(2),
            ]);

            return false;
        }
    }

    /**
     * Submit pending receipts from outbox queue sequentially.
     */
    public function submitPendingQueue(BlockchainGateway $gateway, int $limit = 50): int
    {
        $pending = BlockchainReceipt::whereIn('status', [ReceiptStatus::Pending, ReceiptStatus::Submitted])
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $confirmedCount = 0;

        foreach ($pending as $receipt) {
            if ($this->processReceipt($receipt, $gateway)) {
                $confirmedCount++;
            }
        }

        return $confirmedCount;
    }
}
