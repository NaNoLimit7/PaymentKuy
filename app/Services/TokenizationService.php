<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PaymentToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class TokenizationService
{
    public function tokenize(Customer $customer, array $mockData): PaymentToken
    {
        $tokenType = $mockData['token_type'] ?? $mockData['type'] ?? 'card';

        if (! in_array($tokenType, ['card', 'bank_account', 'ewallet'], true)) {
            throw new \InvalidArgumentException('Unsupported token type.');
        }

        $number = preg_replace('/\D+/', '', (string) ($mockData['number'] ?? ''));

        if (strlen($number) < 4) {
            throw new \InvalidArgumentException('Mock payment data must contain at least four digits.');
        }

        $payload = Arr::except($mockData, ['cvv', 'cvc', 'security_code']);
        $expiresAt = $mockData['expires_at'] ?? null;

        return PaymentToken::create([
            'token' => 'tok_'.bin2hex(random_bytes(16)),
            'customer_id' => $customer->getKey(),
            'token_type' => $tokenType,
            'brand' => $mockData['brand'] ?? null,
            'last4' => substr($number, -4),
            'fingerprint' => hash_hmac(
                'sha256',
                $number,
                (string) config('payment.token_fingerprint_key')
            ),
            'encrypted_payload' => Crypt::encryptString(
                json_encode($payload, JSON_THROW_ON_ERROR)
            ),
            'expires_at' => $expiresAt ? Carbon::parse($expiresAt) : null,
        ]);
    }
}
