<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

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
    }

    /**
     * AC4: Create transaction 2x with same key & body -> 1 transaction created,
     * second response is identical + header Idempotent-Replayed: true.
     */
    public function test_ac4_replayed_response_on_identical_key_and_body(): void
    {
        $order = Order::factory()->create(['merchant_id' => $this->merchant->id]);
        $idempotencyKey = (string) Str::uuid();

        $payload = ['order_uuid' => $order->uuid];

        // First request
        $res1 = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/transactions', $payload);

        $res1->assertStatus(201);
        $res1->assertHeaderMissing('Idempotent-Replayed');

        $transactionCount = Transaction::where('order_id', $order->id)->count();
        $this->assertSame(1, $transactionCount);

        // Second request with exact same key and payload
        $res2 = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/transactions', $payload);

        $res2->assertStatus(201);
        $res2->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($res1->json(), $res2->json());

        // Still only 1 transaction created
        $this->assertSame(1, Transaction::where('order_id', $order->id)->count());
    }

    /**
     * AC5: Same key, different body -> 422 error.
     */
    public function test_ac5_key_reused_with_different_body_returns_422(): void
    {
        $order1 = Order::factory()->create(['merchant_id' => $this->merchant->id]);
        $order2 = Order::factory()->create(['merchant_id' => $this->merchant->id]);
        $idempotencyKey = (string) Str::uuid();

        // First request for order1
        $res1 = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/transactions', ['order_uuid' => $order1->uuid]);

        $res1->assertStatus(201);

        // Second request reusing same key for order2
        $res2 = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/transactions', ['order_uuid' => $order2->uuid]);

        $res2->assertStatus(422);
        $res2->assertJson(['message' => 'Idempotency key reused with different request payload']);
    }
}
