<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transaction_id', 'from_status', 'to_status', 'reason', 'created_at'])]
#[WithoutTimestamps]
class TransactionStateHistory extends Model
{
    protected function casts(): array
    {
        return [
            'from_status' => TransactionStatus::class,
            'to_status' => TransactionStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
