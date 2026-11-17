<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Exceptions\TransactionExpiredException;
use App\Services\AuditLogService;
use App\Services\BlockchainReceiptService;
use App\Services\ReceiptHasher;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'uuid',
    'reference',
    'merchant_id',
    'order_id',
    'payment_token_id',
    'amount',
    'currency',
    'status',
    'gateway_ref',
    'expires_at',
    'paid_at',
    'expired_at',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_minor' => 'integer',
            'status' => TransactionStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function pending(Order $order): self
    {
        return DB::transaction(function () use ($order) {
            $createdAt = now();

            $transaction = self::create([
                'uuid' => (string) Str::uuid(),
                'reference' => self::generateReference(),
                'merchant_id' => $order->merchant_id,
                'order_id' => $order->getKey(),
                'amount' => $order->amount,
                'currency' => $order->currency,
                'status' => TransactionStatus::Pending,
                'expires_at' => $createdAt->copy()->addMinutes(
                    (int) config('payment.transaction_expiry_minutes', 60)
                ),
            ]);

            $transaction->stateHistories()->create([
                'from_status' => null,
                'to_status' => TransactionStatus::Pending,
                'reason' => 'transaction created',
                'created_at' => $createdAt,
            ]);

            app(AuditLogService::class)->record('transaction.created', $transaction, [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'order_id' => $transaction->order_id,
                'status' => TransactionStatus::Pending->value,
                'amount' => (string) $transaction->amount,
                'currency' => $transaction->currency,
            ]);

            $transaction->refresh();

            return $transaction;
        });
    }

    public function proceed(PaymentToken $token, ?string $gatewayRef = null): self
    {
        $transaction = DB::transaction(function () use ($token, $gatewayRef) {
            $transaction = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($transaction->status !== TransactionStatus::Pending) {
                return $transaction;
            }

            if ($transaction->expires_at->lessThanOrEqualTo(now())) {
                $transaction->transitionToExpired('expired before payment');

                return $transaction;
            }

            $transaction->forceFill([
                'payment_token_id' => $token->getKey(),
                'gateway_ref' => $gatewayRef ?: 'MOCKGW-'.bin2hex(random_bytes(8)),
                'paid_at' => now(),
                'status' => TransactionStatus::Proceed,
                'expired_at' => null,
            ])->save();

            $transaction->refresh();

            $transaction->stateHistories()->create([
                'from_status' => TransactionStatus::Pending,
                'to_status' => TransactionStatus::Proceed,
                'reason' => 'payment received',
                'created_at' => $transaction->paid_at,
            ]);

            $receiptHash = app(ReceiptHasher::class)->compute(
                $transaction->reference,
                (string) $transaction->amount_minor,
                $transaction->paid_at->timestamp,
                $transaction->gateway_ref,
            );

            $auditLog = app(AuditLogService::class)->record('transaction.proceed', $transaction, [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'order_id' => $transaction->order_id,
                'status' => TransactionStatus::Proceed->value,
                'amount' => (string) $transaction->amount,
                'currency' => $transaction->currency,
                'gateway_ref' => $transaction->gateway_ref,
                'receipt_hash' => $receiptHash,
            ]);

            app(BlockchainReceiptService::class)->createForProceed($transaction, $auditLog);

            return $transaction;
        });

        if ($transaction->status === TransactionStatus::Expired) {
            throw new TransactionExpiredException('transaction_expired');
        }

        return $transaction;
    }

    public function expire(): self
    {
        return DB::transaction(function () {
            $transaction = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($transaction->status === TransactionStatus::Pending) {
                $transaction->transitionToExpired('expired');
            }

            return $transaction;
        });
    }

    private function transitionToExpired(string $reason): void
    {
        $expiredAt = now();

        $this->forceFill([
            'status' => TransactionStatus::Expired,
            'expired_at' => $expiredAt,
            'paid_at' => null,
            'payment_token_id' => null,
            'gateway_ref' => null,
        ])->save();

        $this->refresh();

        $this->stateHistories()->create([
            'from_status' => TransactionStatus::Pending,
            'to_status' => TransactionStatus::Expired,
            'reason' => $reason,
            'created_at' => $expiredAt,
        ]);

        app(AuditLogService::class)->record('transaction.expired', $this, [
            'transaction_id' => $this->id,
            'reference' => $this->reference,
            'order_id' => $this->order_id,
            'status' => TransactionStatus::Expired->value,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'expired_at' => $expiredAt->toIso8601String(),
        ]);
    }

    private static function generateReference(): string
    {
        return 'TRX-'.Str::ulid();
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentToken(): BelongsTo
    {
        return $this->belongsTo(PaymentToken::class);
    }

    public function stateHistories(): HasMany
    {
        return $this->hasMany(TransactionStateHistory::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function blockchainReceipt(): HasOne
    {
        return $this->hasOne(BlockchainReceipt::class);
    }
}
