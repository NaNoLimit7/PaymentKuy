<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'reference' => 'TRX-'.Str::ulid(),
            'merchant_id' => Merchant::factory(),
            'order_id' => Order::factory(),
            'payment_token_id' => null,
            'amount' => fake()->numberBetween(10_000, 5_000_000),
            'currency' => 'IDR',
            'status' => TransactionStatus::Pending,
            'gateway_ref' => null,
            'expires_at' => now()->addMinutes(60),
            'paid_at' => null,
            'expired_at' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_id' => $order->merchant_id,
            'order_id' => $order->getKey(),
            'amount' => $order->amount,
            'currency' => $order->currency,
        ]);
    }
}
