<?php

namespace Database\Factories;

use App\Models\IdempotencyKey;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'endpoint' => 'POST /api/v1/transactions',
            'request_hash' => hash('sha256', Str::random(40)),
            'status' => 'processing',
            'response_code' => null,
            'response_body' => null,
            'transaction_id' => null,
            'locked_at' => null,
            'expires_at' => now()->addHours(24),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'response_code' => 201,
            'response_body' => ['ok' => true],
            'locked_at' => null,
        ]);
    }
}
