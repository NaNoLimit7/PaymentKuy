<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PaymentToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<PaymentToken>
 */
class PaymentTokenFactory extends Factory
{
    public function definition(): array
    {
        $tokenType = fake()->randomElement(['card', 'bank_account', 'ewallet']);
        $number = fake()->numerify('################');

        return [
            'token' => 'tok_'.bin2hex(random_bytes(16)),
            'customer_id' => Customer::factory(),
            'token_type' => $tokenType,
            'brand' => $tokenType === 'card' ? fake()->randomElement(['visa', 'mastercard']) : null,
            'last4' => substr($number, -4),
            'fingerprint' => hash_hmac(
                'sha256',
                $number,
                (string) config('payment.token_fingerprint_key')
            ),
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'number' => $number,
                'token_type' => $tokenType,
            ], JSON_THROW_ON_ERROR)),
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }
}
