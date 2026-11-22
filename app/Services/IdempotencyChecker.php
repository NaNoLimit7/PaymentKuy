<?php

namespace App\Services;

use App\Exceptions\TransactionExpiredException;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyChecker
{
    /**
     * Handle an idempotent request for a merchant and endpoint.
     */
    public function handle(
        Merchant $merchant,
        string $key,
        string $endpoint,
        array $payload,
        Closure $process
    ): Response {
        $requestHash = hash('sha256', $this->canonicalJson($payload));
        $staleSeconds = (int) config('payment.idempotency_stale_seconds', 60);
        $ttlHours = (int) config('payment.idempotency_ttl_hours', 24);

        // 1. Transaction to check / acquire idempotency lock
        $state = DB::transaction(function () use (
            $merchant, $key, $endpoint, $requestHash, $staleSeconds, $ttlHours
        ) {
            $record = IdempotencyKey::where('merchant_id', $merchant->id)
                ->where('idempotency_key', $key)
                ->where('endpoint', $endpoint)
                ->lockForUpdate()
                ->first();

            if ($record) {
                // Key reused with different payload -> 422
                if ($record->request_hash !== $requestHash) {
                    return [
                        'action' => 'return',
                        'response' => response()->json([
                            'message' => 'Idempotency key reused with different request payload',
                        ], 422),
                    ];
                }

                // Completed previously -> replay stored response
                if ($record->status === 'completed') {
                    $response = response()->json($record->response_body, $record->response_code)
                        ->header('Idempotent-Replayed', 'true');

                    return [
                        'action' => 'return',
                        'response' => $response,
                    ];
                }

                // Currently processing
                $isStale = $record->locked_at && $record->locked_at->diffInSeconds(now()) > $staleSeconds;
                if (! $isStale) {
                    return [
                        'action' => 'return',
                        'response' => response()->json([
                            'message' => 'A request with this idempotency key is currently processing',
                        ], 409),
                    ];
                }

                // Take over stale lock
                $record->update(['locked_at' => now()]);

                return ['action' => 'execute', 'record_id' => $record->id];
            }

            // Create new processing lock
            $newRecord = IdempotencyKey::create([
                'merchant_id' => $merchant->id,
                'idempotency_key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'locked_at' => now(),
                'expires_at' => now()->addHours($ttlHours),
            ]);

            return ['action' => 'execute', 'record_id' => $newRecord->id];
        });

        if ($state['action'] === 'return') {
            return $state['response'];
        }

        $recordId = $state['record_id'];

        // 2. Execute process closure
        try {
            $result = $process();

            if (! $result instanceof Response) {
                $result = response()->json($result);
            }

            $statusCode = $result->getStatusCode();
            $body = json_decode($result->getContent(), true) ?? $result->getContent();

            if ($statusCode < 500) {
                // Determine transaction_id if present
                $trxId = is_array($body) ? ($body['id'] ?? ($body['transaction_id'] ?? null)) : null;

                IdempotencyKey::where('id', $recordId)->update([
                    'status' => 'completed',
                    'response_code' => $statusCode,
                    'response_body' => is_array($body) ? $body : ['content' => $body],
                    'transaction_id' => is_numeric($trxId) ? (int) $trxId : null,
                ]);
            } else {
                IdempotencyKey::where('id', $recordId)->delete();
            }

            return $result;
        } catch (TransactionExpiredException $e) {
            // Deterministic 422 business error -> store and replay
            IdempotencyKey::where('id', $recordId)->update([
                'status' => 'completed',
                'response_code' => 422,
                'response_body' => ['message' => 'transaction_expired'],
            ]);

            return response()->json(['message' => 'transaction_expired'], 422);
        } catch (\Throwable $e) {
            // Technical error -> release key for retry
            IdempotencyKey::where('id', $recordId)->delete();
            throw $e;
        }
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
}
