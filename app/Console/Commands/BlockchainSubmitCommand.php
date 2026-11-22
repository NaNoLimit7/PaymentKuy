<?php

namespace App\Console\Commands;

use App\Contracts\BlockchainGateway;
use App\Enums\ReceiptStatus;
use App\Models\BlockchainReceipt;
use App\Services\BlockchainReceiptService;
use Illuminate\Console\Command;

class BlockchainSubmitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blockchain:submit
                            {--limit=50 : Maximum number of receipts to process}
                            {--orderId= : Specific contract_order_id to submit immediately}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process outbox pending blockchain receipts and anchor them to Sepolia testnet';

    /**
     * Execute the console command.
     */
    public function handle(
        BlockchainReceiptService $service,
        BlockchainGateway $gateway,
    ): int {
        $orderId = $this->option('orderId');

        if ($orderId) {
            $this->info("Memproses receipt untuk Order ID: {$orderId}...");
            $receipt = BlockchainReceipt::where('contract_order_id', $orderId)->first();

            if (! $receipt) {
                $this->error("Receipt untuk order ID '{$orderId}' tidak ditemukan.");

                return Command::FAILURE;
            }

            $success = $service->processReceipt($receipt, $gateway);
            $receipt->refresh();

            if ($success) {
                $this->info(' Berhasil di-anchor ke Sepolia!');
                $this->line("TX Hash      : {$receipt->tx_hash}");
                $this->line("Block Number : {$receipt->block_number}");
                $this->line("Status       : {$receipt->status->value}");
                if ($receipt->tx_hash) {
                    $this->line("Etherscan    : https://sepolia.etherscan.io/tx/{$receipt->tx_hash}");
                }

                return Command::SUCCESS;
            }

            $this->error(" Gagal memproses receipt. Status: {$receipt->status->value}. Error: {$receipt->last_error}");

            return Command::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $this->info("Memeriksa antrean outbox blockchain (limit: {$limit})...");

        $pendingCount = BlockchainReceipt::whereIn('status', [ReceiptStatus::Pending, ReceiptStatus::Submitted])
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->count();

        if ($pendingCount === 0) {
            $this->info('Tidak ada receipt pending yang perlu diproses.');

            return Command::SUCCESS;
        }

        $this->line("Ditemukan {$pendingCount} receipt dalam antrean. Memproses secara berurutan...");

        $bar = $this->output->createProgressBar($pendingCount);
        $bar->start();

        $processed = 0;
        $confirmed = 0;

        $receipts = BlockchainReceipt::whereIn('status', [ReceiptStatus::Pending, ReceiptStatus::Submitted])
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($receipts as $receipt) {
            if ($service->processReceipt($receipt, $gateway)) {
                $confirmed++;
            }
            $processed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Selesai memproses antrean.');
        $this->table(
            ['Total Diproses', 'Berhasil Confirmed', 'Gagal / Tertunda'],
            [[$processed, $confirmed, $processed - $confirmed]]
        );

        return Command::SUCCESS;
    }
}
