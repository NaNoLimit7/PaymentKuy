<?php

namespace App\Http\Controllers\Api;

use App\Contracts\BlockchainGateway;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly BlockchainGateway $blockchainGateway,
    ) {}

    /**
     * GET /api/v1/audit/verify
     * Verify off-chain hash chain integrity across all audit logs.
     */
    public function verify(): JsonResponse
    {
        $isValid = $this->auditService->verifyChain();
        $totalLogs = AuditLog::count();

        return response()->json([
            'valid' => $isValid,
            'total_logs' => $totalLogs,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/audit/transactions/{uuid}
     * Verify a specific transaction's audit logs and on-chain receipt.
     */
    public function transaction(string $uuid): JsonResponse
    {
        $transaction = Transaction::where('uuid', $uuid)
            ->with(['auditLogs' => fn ($q) => $q->orderBy('id'), 'blockchainReceipt'])
            ->firstOrFail();

        $onChainVerified = false;
        if ($transaction->blockchainReceipt) {
            try {
                $onChainVerified = $this->blockchainGateway->verifyReceipt($transaction->blockchainReceipt);
            } catch (\Throwable) {
                $onChainVerified = false;
            }
        }

        return response()->json([
            'transaction_uuid' => $transaction->uuid,
            'reference' => $transaction->reference,
            'status' => $transaction->status->value,
            'audit_chain_valid' => $this->auditService->verifyChain(),
            'audit_logs' => $transaction->auditLogs->map(fn ($log) => [
                'event_type' => $log->event_type,
                'payload' => $log->payload,
                'prev_hash' => $log->prev_hash,
                'record_hash' => $log->record_hash,
                'payload_hash' => $log->payload_hash,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'blockchain' => $transaction->blockchainReceipt ? [
                'contract_order_id' => $transaction->blockchainReceipt->contract_order_id,
                'receipt_hash' => $transaction->blockchainReceipt->receipt_hash,
                'status' => $transaction->blockchainReceipt->status->value,
                'tx_hash' => $transaction->blockchainReceipt->tx_hash,
                'block_number' => $transaction->blockchainReceipt->block_number,
                'network' => $transaction->blockchainReceipt->network,
                'on_chain_verified' => $onChainVerified,
                'etherscan_url' => $transaction->blockchainReceipt->tx_hash
                    ? "https://sepolia.etherscan.io/tx/{$transaction->blockchainReceipt->tx_hash}"
                    : null,
            ] : null,
        ]);
    }
}
