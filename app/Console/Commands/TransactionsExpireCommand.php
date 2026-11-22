<?php

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Console\Command;

class TransactionsExpireCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire pending transactions that have passed their expires_at timestamp';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expiredCount = 0;

        Transaction::query()
            ->where('status', TransactionStatus::Pending)
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($transactions) use (&$expiredCount) {
                foreach ($transactions as $transaction) {
                    $transaction->expire();
                    $expiredCount++;
                }
            });

        $this->info("Expired {$expiredCount} pending transaction(s).");

        return Command::SUCCESS;
    }
}
