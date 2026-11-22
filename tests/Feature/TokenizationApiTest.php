<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TokenizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->apiKey = 'pk_test_'.Str::random(32);
        $this->merchant = Merchant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Merchant',
            'api_key_hash' => hash('sha256', $this->apiKey),
            'status' => 'active',
        ]);

        $this->customer = Customer::factory()->create();
    }

    public function test_tokenize_card_successfully(): void
    {
        $res = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
        ])->postJson('/api/v1/tokens', [
            'customer_uuid' => $this->customer->uuid,
            'token_type' => 'card',
            'brand' => 'Visa',
            'number' => '4111222233334444',
            'expires_at' => '2028-12-31',
        ]);

        $res->assertStatus(201);
        $res->assertJsonStructure([
            'token',
            'token_type',
            'brand',
            'last4',
            'fingerprint',
            'expires_at',
            'created_at',
        ]);

        $this->assertSame('4444', $res->json('last4'));
        $this->assertStringStartsWith('tok_', $res->json('token'));
    }

    public function test_tokenization_requires_authentication(): void
    {
        $res = $this->postJson('/api/v1/tokens', [
            'customer_uuid' => $this->customer->uuid,
            'token_type' => 'card',
            'number' => '4111222233334444',
        ]);

        $res->assertStatus(401);
    }
}
