<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditLogService
{
    public function record(string $event, ?Transaction $transaction, array $payload): AuditLog
    {
        return DB::transaction(function () use ($event, $transaction, $payload) {
            $last = AuditLog::query()->orderByDesc('id')->lockForUpdate()->first();

            $prevHash = $last?->record_hash ?? str_repeat('0', 64);
            $createdAt = now();
            $payloadHash = hash('sha256', $this->canonicalJson($payload));
            $recordHash = hash(
                'sha256',
                $prevHash.$event.$payloadHash.$this->timestamp($createdAt)
            );

            return AuditLog::create([
                'transaction_id' => $transaction?->getKey(),
                'event_type' => $event,
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'prev_hash' => $prevHash,
                'record_hash' => $recordHash,
                'created_at' => $createdAt,
            ]);
        });
    }

    public function verifyChain(): bool
    {
        $previousHash = str_repeat('0', 64);

        foreach (AuditLog::query()->orderBy('id')->cursor() as $log) {
            $payloadHash = hash('sha256', $this->canonicalJson($log->payload));

            if (! hash_equals($log->payload_hash, $payloadHash)) {
                return false;
            }

            if (! hash_equals($log->prev_hash, $previousHash)) {
                return false;
            }

            $expectedHash = hash(
                'sha256',
                $log->prev_hash.$log->event_type.$log->payload_hash.$this->timestamp($log->created_at)
            );

            if (! hash_equals($log->record_hash, $expectedHash)) {
                return false;
            }

            $previousHash = $log->record_hash;
        }

        return true;
    }

    private function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->sortKeys($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->sortKeys($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item) => $this->sortKeys($item), $value);
    }

    private function timestamp(Carbon $createdAt): string
    {
        return $createdAt->format('Y-m-d H:i:s.u');
    }
}
