<?php

namespace Tests\Feature;

use App\DTOs\ReceiptResult;
use App\Enums\ReceiptStatus;
use App\Exceptions\BlockchainGatewayException;
use App\Gateways\HttpBlockchainGateway;
use App\Models\BlockchainReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpBlockchainGatewayTest extends TestCase
{
    use RefreshDatabase;

    private HttpBlockchainGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payment.blockchain.service_url' => 'http://127.0.0.1:3001',
            'payment.blockchain.service_token' => 'test-token',
        ]);
        $this->gateway = new HttpBlockchainGateway;
    }

    public function test_has_receipt_returns_true_when_exists(): void
    {
        Http::fake([
            'http://127.0.0.1:3001/receipts/ORDER-123' => Http::response(['exists' => true, 'status' => 'SETTLED'], 200),
        ]);

        $this->assertTrue($this->gateway->hasReceipt('ORDER-123'));
    }

    public function test_has_receipt_returns_false_when_not_exists(): void
    {
        Http::fake([
            'http://127.0.0.1:3001/receipts/ORDER-999' => Http::response(['exists' => false], 200),
        ]);

        $this->assertFalse($this->gateway->hasReceipt('ORDER-999'));
    }

    public function test_record_receipt_successful_response(): void
    {
        $this->seed();
        $receipt = BlockchainReceipt::where('status', ReceiptStatus::Pending)->firstOrFail();

        Http::fake([
            'http://127.0.0.1:3001/receipts' => Http::response([
                'txHash' => '0xabcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
                'blockNumber' => 12095000,
                'status' => 'confirmed',
            ], 200),
        ]);

        $result = $this->gateway->recordReceipt($receipt);

        $this->assertInstanceOf(ReceiptResult::class, $result);
        $this->assertSame('0xabcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890', $result->txHash);
        $this->assertSame(12095000, $result->blockNumber);
        $this->assertSame('confirmed', $result->status);
    }

    public function test_record_receipt_throws_already_recorded_exception_on_409(): void
    {
        $this->seed();
        $receipt = BlockchainReceipt::where('status', ReceiptStatus::Pending)->firstOrFail();

        Http::fake([
            'http://127.0.0.1:3001/receipts' => Http::response([
                'code' => 'ALREADY_RECORDED',
                'message' => 'Order ID already recorded on-chain',
            ], 409),
        ]);

        $this->expectException(BlockchainGatewayException::class);
        $this->expectExceptionCode(409);

        $this->gateway->recordReceipt($receipt);
    }

    public function test_verify_receipt_returns_valid_boolean(): void
    {
        $this->seed();
        $receipt = BlockchainReceipt::where('status', ReceiptStatus::Pending)->firstOrFail();

        Http::fake([
            'http://127.0.0.1:3001/receipts/verify' => Http::response([
                'valid' => true,
                'storedHash' => $receipt->receipt_hash,
            ], 200),
        ]);

        $this->assertTrue($this->gateway->verifyReceipt($receipt));
    }
}
