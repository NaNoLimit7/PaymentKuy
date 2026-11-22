<?php

namespace Tests\Feature;

use App\Enums\ReceiptStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentToken;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionApiTest extends TestCase
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
            'name' => 'Test Merchant',
            'api_key_hash' => hash('sha256', $this->apiKey),
            'status' => 'active',
        ]);

        $this->customer = Customer::factory()->create();
        $this->token = PaymentToken::factory()->create(['customer_id' => $this->customer->id]);
    }

    /**
     * AC2: New transaction is pending, paid_at NULL, expires_at set.
     */
    public function test_ac2_create_pending_transaction(): void
    {
        $order = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id]);

        $res = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/transactions', ['order_uuid' => $order->uuid]);

        $res->assertStatus(201);
        $res->assertJsonPath('status', 'pending');
        $this->assertNotNull($res->json('expires_at'));

        $trx = Transaction::where('uuid', $res->json('uuid'))->firstOrFail();
        $this->assertSame(TransactionStatus::Pending, $trx->status);
        $this->assertNull($trx->paid_at);
        $this->assertNotNull($trx->expires_at);
    }

    /**
     * AC3: pay() transitions pending -> proceed, fills paid_at, creates history, audit log,
     * and pending blockchain receipt in one DB transaction.
     */
    public function test_ac3_pay_transaction_transitions_to_proceed_and_creates_receipt(): void
    {
        $order = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id]);
        $trx = Transaction::pending($order);

        $res = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/transactions/{$trx->uuid}/pay", [
            'payment_token' => $this->token->token,
            'gateway_ref' => 'MOCKGW-1234567890abcdef',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('status', 'proceed');

        $trx->refresh();
        $this->assertSame(TransactionStatus::Proceed, $trx->status);
        $this->assertNotNull($trx->paid_at);
        $this->assertSame('MOCKGW-1234567890abcdef', $trx->gateway_ref);

        // Blockchain receipt was created with status Pending
        $this->assertNotNull($trx->blockchainReceipt);
        $this->assertSame(ReceiptStatus::Pending, $trx->blockchainReceipt->status);
        $this->assertSame($trx->reference, $trx->blockchainReceipt->contract_order_id);
    }

    /**
     * AC7: Paying a transaction that already proceeded (new key) -> 409 conflict,
     * does not modify data.
     */
    public function test_ac7_pay_already_proceeded_transaction_returns_409(): void
    {
        $order = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id]);
        $trx = Transaction::pending($order);
        $trx->proceed($this->token);

        $res = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/transactions/{$trx->uuid}/pay", [
            'payment_token' => $this->token->token,
        ]);

        $res->assertStatus(409);
        $res->assertJson(['message' => 'Transaction has already proceeded']);
    }

    /**
     * AC13: Paying a transaction past expires_at -> 422 transaction_expired, status becomes expired.
     */
    public function test_ac13_lazy_expiry_when_paying_expired_transaction(): void
    {
        $order = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id]);
        $trx = Transaction::pending($order);
        $trx->update(['expires_at' => now()->subMinutes(5)]);

        $res = $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/transactions/{$trx->uuid}/pay", [
            'payment_token' => $this->token->token,
        ]);

        $res->assertStatus(422);
        $res->assertJson(['message' => 'transaction_expired']);

        $trx->refresh();
        $this->assertSame(TransactionStatus::Expired, $trx->status);
        $this->assertNotNull($trx->expired_at);
    }

    /**
     * AC14 & AC15: One order can have multiple transactions with unique references
     * and multiple proceed transactions create receipts with unique contract_order_ids.
     */
    public function test_ac14_and_ac15_multiple_transactions_per_order(): void
    {
        $order = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id]);

        $trx1 = Transaction::pending($order);
        $trx2 = Transaction::pending($order);

        $this->assertNotSame($trx1->reference, $trx2->reference);

        $trx1->proceed($this->token);
        $trx2->proceed($this->token);

        $this->assertSame(2, $order->transactions()->count());
        $this->assertNotNull($trx1->blockchainReceipt);
        $this->assertNotNull($trx2->blockchainReceipt);
        $this->assertNotSame(
            $trx1->blockchainReceipt->contract_order_id,
            $trx2->blockchainReceipt->contract_order_id
        );
    }
}
