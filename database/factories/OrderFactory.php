<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'order_number' => 'ORD-'.Str::ulid(),
            'amount' => fake()->numberBetween(10_000, 5_000_000),
            'currency' => 'IDR',
        ];
    }
}
