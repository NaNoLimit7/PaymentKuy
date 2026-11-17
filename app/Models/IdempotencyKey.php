<?php

namespace App\Models;

use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'merchant_id',
    'idempotency_key',
    'endpoint',
    'request_hash',
    'status',
    'response_code',
    'response_body',
    'transaction_id',
    'locked_at',
    'expires_at',
])]
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_code' => 'integer',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
