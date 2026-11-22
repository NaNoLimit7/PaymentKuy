<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class IdempotencyPurgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'idempotency:purge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge expired idempotency keys';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = IdempotencyKey::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Purged {$deleted} expired idempotency key(s).");

        return Command::SUCCESS;
    }
}
