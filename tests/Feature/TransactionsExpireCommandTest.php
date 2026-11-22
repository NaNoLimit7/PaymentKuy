<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionsExpireCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expire_command_expires_past_due_pending_transactions(): void
    {
        $this->seed();

        $merchant = Merchant::firstOrFail();
        $order = Order::where('merchant_id', $merchant->id)->firstOrFail();

        // Create transaction that is already past its expires_at
        $pastTransaction = Transaction::pending($order);
        $pastTransaction->update(['expires_at' => now()->subMinutes(10)]);

        // Create fresh transaction that should not expire
        $freshTransaction = Transaction::pending($order);

        $this->artisan('transactions:expire')
            ->expectsOutputToContain('Expired 1 pending transaction(s).')
            ->assertSuccessful();

        $pastTransaction->refresh();
        $freshTransaction->refresh();

        $this->assertSame(TransactionStatus::Expired, $pastTransaction->status);
        $this->assertNotNull($pastTransaction->expired_at);

        $this->assertSame(TransactionStatus::Pending, $freshTransaction->status);
        $this->assertNull($freshTransaction->expired_at);
    }
}
