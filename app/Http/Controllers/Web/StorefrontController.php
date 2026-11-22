<?php

namespace App\Http\Controllers\Web;

use App\Contracts\BlockchainGateway;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\BlockchainReceiptService;
use App\Services\TokenizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly TokenizationService $tokenization,
        private readonly BlockchainReceiptService $receiptService,
        private readonly BlockchainGateway $blockchainGateway,
    ) {}

    /**
     * Show Mock Storefront Home.
     */
    public function index(): View
    {
        $merchant = Merchant::first() ?? Merchant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Toko Alpha',
            'api_key_hash' => hash('sha256', 'pk_test_paymentkuy_filkom_demo_2026'),
            'status' => 'active',
        ]);

        $recentTransactions = Transaction::query()
            ->with(['order', 'blockchainReceipt'])
            ->latest()
            ->limit(5)
            ->get();

        return view('storefront.index', [
            'merchant' => $merchant,
            'contractAddress' => config('payment.receipt.contract_address', '0x26B92A0F7D3d9E97A8A3DDaEa1c2CB6068EbD7aD'),
            'recentTransactions' => $recentTransactions,
        ]);
    }

    /**
     * Complete Storefront Checkout (Tokens -> Transact -> Pay -> Anchor on Sepolia).
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_email' => ['required', 'email', 'max:190'],
            'amount' => ['required', 'numeric', 'min:1000'],
            'item_name' => ['required', 'string', 'max:150'],
            'token_type' => ['required', 'in:card,bank_account,ewallet'],
            'mock_number' => ['required', 'string', 'min:4'],
            'brand' => ['nullable', 'string'],
        ]);

        $merchant = Merchant::firstOrFail();

        // 1. Find or create Customer
        $customer = Customer::firstOrCreate(
            ['email' => $validated['customer_email']],
            ['name' => $validated['customer_name'], 'uuid' => (string) Str::uuid()]
        );

        // 2. Create Order
        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-'.strtoupper(Str::random(10)),
            'amount' => $validated['amount'],
            'currency' => 'IDR',
        ]);

        // 3. Tokenize Payment Data (No raw credentials stored)
        $token = $this->tokenization->tokenize($customer, [
            'token_type' => $validated['token_type'],
            'number' => $validated['mock_number'],
            'brand' => $validated['brand'] ?? ucfirst($validated['token_type']),
        ]);

        // 4. Create Pending Transaction
        $transaction = Transaction::pending($order);

        // 5. Proceed Payment
        $transaction->proceed($token, 'MOCKGW-'.bin2hex(random_bytes(8)));
        $transaction->refresh();

        // 6. Anchor to Ethereum Sepolia via Outbox Processor
        $receipt = $transaction->blockchainReceipt;
        $anchored = false;
        if ($receipt) {
            $anchored = $this->receiptService->processReceipt($receipt, $this->blockchainGateway);
            $receipt->refresh();
        }

        return response()->json([
            'success' => true,
            'reference' => $transaction->reference,
            'order_number' => $order->order_number,
            'amount' => number_format($transaction->amount, 0, ',', '.'),
            'status' => $transaction->status->value,
            'token' => $token->token,
            'last4' => $token->last4,
            'token_type' => $token->token_type,
            'receipt_hash' => $receipt?->receipt_hash,
            'tx_hash' => $receipt?->tx_hash,
            'block_number' => $receipt?->block_number,
            'blockchain_status' => $receipt?->status->value,
            'anchored' => $anchored,
            'etherscan_url' => $receipt?->tx_hash ? "https://sepolia.etherscan.io/tx/{$receipt->tx_hash}" : null,
            'verify_url' => url("/verify/{$transaction->reference}"),
        ]);
    }
}
