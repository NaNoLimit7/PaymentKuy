<?php

namespace App\Models;

use Database\Factories\PaymentTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'token',
    'customer_id',
    'token_type',
    'brand',
    'last4',
    'fingerprint',
    'encrypted_payload',
    'expires_at',
    'revoked_at',
])]
#[Hidden(['fingerprint', 'encrypted_payload'])]
class PaymentToken extends Model
{
    /** @use HasFactory<PaymentTokenFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
