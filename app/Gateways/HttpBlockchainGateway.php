<?php

namespace App\Gateways;

use App\Contracts\BlockchainGateway;
use App\DTOs\ReceiptResult;
use App\Exceptions\BlockchainGatewayException;
use App\Models\BlockchainReceipt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpBlockchainGateway implements BlockchainGateway
{
    private string $serviceUrl;

    private string $serviceToken;

    private int $timeout;

    public function __construct()
    {
        $this->serviceUrl = rtrim(config('payment.blockchain.service_url', 'http://127.0.0.1:3001'), '/');
        $this->serviceToken = config('payment.blockchain.service_token', '');
        $this->timeout = (int) config('payment.blockchain.http_timeout', 60);
    }

    /**
     * Check if a receipt exists on-chain via GET /receipts/{orderId}.
     */
    public function hasReceipt(string $contractOrderId): bool
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['X-Internal-Token' => $this->serviceToken])
                ->get("{$this->serviceUrl}/receipts/".urlencode($contractOrderId));

            if ($response->successful()) {
                return (bool) $response->json('exists', false);
            }

            Log::warning('HttpBlockchainGateway::hasReceipt failed', [
                'order_id' => $contractOrderId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('HttpBlockchainGateway::hasReceipt exception', [
                'order_id' => $contractOrderId,
                'exception' => $e->getMessage(),
            ]);
            throw new BlockchainGatewayException('Failed to check receipt existence: '.$e->getMessage(), 'RPC_ERROR', null, 0, $e);
        }
    }

    /**
     * Record receipt on-chain via POST /receipts.
     */
    public function recordReceipt(BlockchainReceipt $receipt): ReceiptResult
    {
        $payload = [
            'orderId' => $receipt->contract_order_id,
            'receiptHash' => $receipt->receipt_hash,
            'amount' => (string) $receipt->amount_minor,
            'settlementTime' => (int) $receipt->settlement_time,
            'gatewayRef' => $receipt->gateway_ref,
            'currency' => $receipt->currency ?: 'IDR',
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Internal-Token' => $this->serviceToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post("{$this->serviceUrl}/receipts", $payload);

            if ($response->successful()) {
                $data = $response->json();

                return new ReceiptResult(
                    txHash: $data['txHash'],
                    blockNumber: (int) $data['blockNumber'],
                    status: $data['status'] ?? 'confirmed'
                );
            }

            $status = $response->status();
            $error = $response->json();
            $code = $error['code'] ?? 'UNKNOWN_ERROR';
            $msg = $error['message'] ?? $response->body();
            $txHash = $error['txHash'] ?? null;

            throw new BlockchainGatewayException(
                message: "Blockchain service error ({$code}): {$msg}",
                errorCode: $code,
                txHash: $txHash,
                code: $status
            );
        } catch (ConnectionException $e) {
            throw new BlockchainGatewayException(
                message: 'Connection timeout or network failure with blockchain service: '.$e->getMessage(),
                errorCode: 'CONFIRMATION_TIMEOUT',
                code: 504,
                previous: $e
            );
        }
    }

    /**
     * Verify on-chain receipt validity via POST /receipts/verify.
     */
    public function verifyReceipt(BlockchainReceipt $receipt): bool
    {
        $payload = [
            'orderId' => $receipt->contract_order_id,
            'amount' => (string) $receipt->amount_minor,
            'settlementTime' => (int) $receipt->settlement_time,
            'gatewayRef' => $receipt->gateway_ref,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Internal-Token' => $this->serviceToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post("{$this->serviceUrl}/receipts/verify", $payload);

            if ($response->successful()) {
                return (bool) $response->json('valid', false);
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('HttpBlockchainGateway::verifyReceipt exception', [
                'order_id' => $receipt->contract_order_id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
