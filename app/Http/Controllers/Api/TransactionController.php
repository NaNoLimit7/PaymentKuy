<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Exceptions\TransactionExpiredException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentToken;
use App\Models\Transaction;
use App\Services\IdempotencyChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{
    public function __construct(private readonly IdempotencyChecker $idempotency) {}

    /**
     * POST /api/v1/transactions
     * Create a pending transaction for an order (Idempotent).
     */
    public function store(Request $request): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            return response()->json(['message' => 'Missing Idempotency-Key header'], 400);
        }

        $merchant = $request->attributes->get('merchant');

        $validated = $request->validate([
            'order_uuid' => ['required_without:order_id', 'string', 'uuid'],
            'order_id' => ['required_without:order_uuid', 'integer'],
        ]);

        return $this->idempotency->handle(
            merchant: $merchant,
            key: $idempotencyKey,
            endpoint: '/api/v1/transactions',
            payload: $validated,
            process: function () use ($merchant, $validated): JsonResponse {
                $orderQuery = Order::where('merchant_id', $merchant->id);

                $order = isset($validated['order_uuid'])
                    ? $orderQuery->where('uuid', $validated['order_uuid'])->firstOrFail()
                    : $orderQuery->where('id', $validated['order_id'])->firstOrFail();

                $transaction = Transaction::pending($order);

                return response()->json([
                    'uuid' => $transaction->uuid,
                    'reference' => $transaction->reference,
                    'order_uuid' => $order->uuid,
                    'order_number' => $order->order_number,
                    'amount' => (string) $transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => $transaction->status->value,
                    'expires_at' => $transaction->expires_at->toIso8601String(),
                    'created_at' => $transaction->created_at->toIso8601String(),
                ], 201);
            }
        );
    }

    /**
     * POST /api/v1/transactions/{uuid}/pay
     * Pay a pending transaction (Idempotent).
     */
    public function pay(Request $request, string $uuid): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            return response()->json(['message' => 'Missing Idempotency-Key header'], 400);
        }

        $merchant = $request->attributes->get('merchant');

        $validated = $request->validate([
            'payment_token' => ['required', 'string'],
            'gateway_ref' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->idempotency->handle(
            merchant: $merchant,
            key: $idempotencyKey,
            endpoint: "/api/v1/transactions/{$uuid}/pay",
            payload: $validated,
            process: function () use ($merchant, $uuid, $validated): JsonResponse {
                $transaction = Transaction::where('merchant_id', $merchant->id)
                    ->where('uuid', $uuid)
                    ->firstOrFail();

                // PRD 4.1: proceed on proceed -> 409 Conflict
                if ($transaction->status === TransactionStatus::Proceed) {
                    return response()->json([
                        'message' => 'Transaction has already proceeded',
                    ], 409);
                }

                // PRD 4.1: proceed on expired -> 422
                if ($transaction->status === TransactionStatus::Expired) {
                    return response()->json([
                        'message' => 'transaction_expired',
                    ], 422);
                }

                $token = PaymentToken::where('token', $validated['payment_token'])
                    ->whereNull('revoked_at')
                    ->firstOrFail();

                try {
                    $transaction->proceed($token, $validated['gateway_ref'] ?? null);
                } catch (TransactionExpiredException $e) {
                    return response()->json(['message' => 'transaction_expired'], 422);
                }

                $transaction->refresh();

                return response()->json([
                    'uuid' => $transaction->uuid,
                    'reference' => $transaction->reference,
                    'amount' => (string) $transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => $transaction->status->value,
                    'gateway_ref' => $transaction->gateway_ref,
                    'paid_at' => $transaction->paid_at?->toIso8601String(),
                ], 200);
            }
        );
    }

    /**
     * GET /api/v1/transactions/{uuid}
     * Get transaction details, state histories, and blockchain receipt status.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $transaction = Transaction::where('merchant_id', $merchant->id)
            ->where('uuid', $uuid)
            ->with(['stateHistories', 'blockchainReceipt', 'order'])
            ->firstOrFail();

        return response()->json([
            'uuid' => $transaction->uuid,
            'reference' => $transaction->reference,
            'order_uuid' => $transaction->order->uuid,
            'order_number' => $transaction->order->order_number,
            'amount' => (string) $transaction->amount,
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->currency,
            'status' => $transaction->status->value,
            'gateway_ref' => $transaction->gateway_ref,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'paid_at' => $transaction->paid_at?->toIso8601String(),
            'expired_at' => $transaction->expired_at?->toIso8601String(),
            'state_histories' => $transaction->stateHistories->map(fn ($h) => [
                'from_status' => $h->from_status?->value,
                'to_status' => $h->to_status->value,
                'reason' => $h->reason,
                'created_at' => $h->created_at->toIso8601String(),
            ]),
            'blockchain_receipt' => $transaction->blockchainReceipt ? [
                'status' => $transaction->blockchainReceipt->status->value,
                'contract_order_id' => $transaction->blockchainReceipt->contract_order_id,
                'receipt_hash' => $transaction->blockchainReceipt->receipt_hash,
                'tx_hash' => $transaction->blockchainReceipt->tx_hash,
                'block_number' => $transaction->blockchainReceipt->block_number,
                'network' => $transaction->blockchainReceipt->network,
                'attempts' => $transaction->blockchainReceipt->attempts,
                'last_error' => $transaction->blockchainReceipt->last_error,
                'confirmed_at' => $transaction->blockchainReceipt->confirmed_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * GET /api/v1/orders/{uuid}/transactions
     * Get all transactions for an order.
     */
    public function byOrder(Request $request, string $uuid): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $order = Order::where('merchant_id', $merchant->id)
            ->where('uuid', $uuid)
            ->with(['transactions' => fn ($q) => $q->orderByDesc('id')])
            ->firstOrFail();

        return response()->json([
            'order_uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'amount' => (string) $order->amount,
            'currency' => $order->currency,
            'transactions' => $order->transactions->map(fn ($t) => [
                'uuid' => $t->uuid,
                'reference' => $t->reference,
                'amount' => (string) $t->amount,
                'currency' => $t->currency,
                'status' => $t->status->value,
                'expires_at' => $t->expires_at?->toIso8601String(),
                'paid_at' => $t->paid_at?->toIso8601String(),
                'expired_at' => $t->expired_at?->toIso8601String(),
            ]),
        ]);
    }
}
