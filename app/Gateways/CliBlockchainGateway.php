<?php

namespace App\Gateways;

use App\Contracts\BlockchainGateway;
use App\DTOs\ReceiptResult;
use App\Exceptions\BlockchainGatewayException;
use App\Models\BlockchainReceipt;
use Symfony\Component\Process\Process;

class CliBlockchainGateway implements BlockchainGateway
{
    private string $scriptDir;

    public function __construct()
    {
        $this->scriptDir = base_path('blockchain/scripts');
    }

    public function hasReceipt(string $contractOrderId): bool
    {
        $process = new Process([
            'node',
            $this->scriptDir.'/verifyReceipt.js',
            '--orderId',
            $contractOrderId,
        ], base_path('blockchain'));

        $process->setTimeout(30);
        $process->run();

        $output = $process->getOutput();
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $json = json_decode($line, true);

                return (bool) ($json['exists'] ?? false);
            }
        }

        return false;
    }

    public function recordReceipt(BlockchainReceipt $receipt): ReceiptResult
    {
        $process = new Process([
            'node',
            $this->scriptDir.'/anchorReceipt.js',
            '--orderId',
            $receipt->contract_order_id,
            '--amount',
            (string) $receipt->amount_minor,
            '--settlementTime',
            (string) $receipt->settlement_time,
            '--gatewayRef',
            $receipt->gateway_ref,
            '--receiptHash',
            $receipt->receipt_hash,
            '--currency',
            $receipt->currency ?: 'IDR',
        ], base_path('blockchain'));

        $process->setTimeout(120);
        $process->run();

        $output = $process->getOutput();
        $json = null;

        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $json = json_decode($line, true);
                break;
            }
        }

        if (! $json || ! ($json['success'] ?? false)) {
            $error = $json['error'] ?? 'CLI anchor script execution failed';
            throw new BlockchainGatewayException($error, 'CLI_ERROR');
        }

        return new ReceiptResult(
            txHash: $json['txHash'],
            blockNumber: (int) $json['blockNumber'],
            status: 'confirmed'
        );
    }

    public function verifyReceipt(BlockchainReceipt $receipt): bool
    {
        $process = new Process([
            'node',
            $this->scriptDir.'/verifyReceipt.js',
            '--orderId',
            $receipt->contract_order_id,
        ], base_path('blockchain'));

        $process->setTimeout(30);
        $process->run();

        $output = $process->getOutput();
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $json = json_decode($line, true);
                if (! ($json['exists'] ?? false)) {
                    return false;
                }

                return hash_equals($json['receiptHash'] ?? '', $receipt->receipt_hash);
            }
        }

        return false;
    }
}
