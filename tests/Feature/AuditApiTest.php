<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentToken;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

    private Customer $customer;

    private PaymentToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->apiKey = 'pk_test_'.Str::random(32);
        $this->merchant = Merchant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Audit Test Merchant',
            'api_key_hash' => hash('sha256', $this->apiKey),
            'status' => 'active',
        ]);

        $this->customer = Customer::factory()->create();
        $this->token = PaymentToken::factory()->create(['customer_id' => $this->customer->id]);
    }

    /**
     * Test verification endpoint requires authentication.
     */
    public function test_audit_verify_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/audit/verify');

        $response->assertStatus(401);
    }

    /**
     * Test verification endpoint succeeds when audit hash chain is intact.
     */
    public function test_audit_verify_returns_valid_chain(): void
    {
        $order = Order::factory()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
        ]);

        $transaction = Transaction::pending($order);
        $transaction->proceed($this->token);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson('/api/v1/audit/verify');

        $response->assertStatus(200);
        $response->assertJsonPath('valid', true);
        $this->assertGreaterThan(0, $response->json('total_logs'));
    }

    /**
     * Test verification endpoint detects tampering when a log record is altered.
     */
    public function test_audit_verify_detects_tampered_record(): void
    {
        $order = Order::factory()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
        ]);

        $transaction = Transaction::pending($order);
        $transaction->proceed($this->token);

        // Tamper with payload in the latest audit log
        $lastLog = AuditLog::latest('id')->firstOrFail();
        $tamperedPayload = $lastLog->payload;
        $tamperedPayload['tampered'] = true;

        AuditLog::where('id', $lastLog->id)->update([
            'payload' => json_encode($tamperedPayload),
        ]);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson('/api/v1/audit/verify');

        $response->assertStatus(200);
        $response->assertJsonPath('valid', false);
    }

    /**
     * Test transaction audit trail retrieval with on-chain status.
     */
    public function test_transaction_audit_trail_endpoint(): void
    {
        $order = Order::factory()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
        ]);

        $transaction = Transaction::pending($order);
        $transaction->proceed($this->token);

        $response = $this->withHeader('X-Api-Key', $this->apiKey)
            ->getJson("/api/v1/audit/transactions/{$transaction->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('transaction_uuid', $transaction->uuid);
        $response->assertJsonPath('status', 'proceed');
        $this->assertIsArray($response->json('audit_logs'));
        $this->assertNotEmpty($response->json('audit_logs'));
    }
}
