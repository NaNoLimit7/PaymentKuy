<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id',
    'audit_log_id',
    'contract_order_id',
    'receipt_hash',
    'amount_minor',
    'settlement_time',
    'gateway_ref',
    'currency',
    'network',
    'contract_address',
    'tx_hash',
    'block_number',
    'status',
    'attempts',
    'last_error',
    'next_attempt_at',
    'submitted_at',
    'confirmed_at',
])]
class BlockchainReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'settlement_time' => 'integer',
            'block_number' => 'integer',
            'attempts' => 'integer',
            'status' => ReceiptStatus::class,
            'next_attempt_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }
}
