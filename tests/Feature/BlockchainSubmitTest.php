<?php

namespace Tests\Feature;

use App\Enums\ReceiptStatus;
use App\Gateways\MockBlockchainGateway;
use App\Models\BlockchainReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockchainSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MockBlockchainGateway::reset();
        config(['payment.blockchain.driver' => 'mock']);
    }

    public function test_submit_command_processes_pending_receipts_with_mock_driver(): void
    {
        $this->seed();

        $pendingBefore = BlockchainReceipt::where('status', ReceiptStatus::Pending)->count();
        $this->assertGreaterThan(0, $pendingBefore);

        $this->artisan('blockchain:submit')
            ->assertSuccessful();

        $pendingAfter = BlockchainReceipt::where('status', ReceiptStatus::Pending)->count();
        $this->assertSame(0, $pendingAfter);

        $confirmed = BlockchainReceipt::where('status', ReceiptStatus::Confirmed)->get();
        foreach ($confirmed as $receipt) {
            $this->assertNotNull($receipt->tx_hash);
            $this->assertNotNull($receipt->confirmed_at);
        }
    }

    public function test_submit_command_is_idempotent_for_already_recorded_receipts(): void
    {
        $this->seed();

        // First run
        $this->artisan('blockchain:submit')->assertSuccessful();

        $firstConfirmed = BlockchainReceipt::all(['id', 'tx_hash', 'status']);

        // Second run should not change hashes or duplicate
        $this->artisan('blockchain:submit')->assertSuccessful();

        $secondConfirmed = BlockchainReceipt::all(['id', 'tx_hash', 'status']);
        $this->assertEquals($firstConfirmed->toArray(), $secondConfirmed->toArray());
    }

    public function test_submit_single_order_by_option(): void
    {
        $this->seed();

        $pending = BlockchainReceipt::where('status', ReceiptStatus::Pending)->firstOrFail();

        $this->artisan('blockchain:submit', ['--orderId' => $pending->contract_order_id])
            ->assertSuccessful();

        $pending->refresh();
        $this->assertSame(ReceiptStatus::Confirmed, $pending->status);
        $this->assertNotNull($pending->tx_hash);
    }
}
