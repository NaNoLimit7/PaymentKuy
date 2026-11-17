<?php

namespace Database\Seeders;

use App\Enums\ReceiptStatus;
use App\Models\BlockchainReceipt;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentToken;
use App\Models\Transaction;
use App\Services\AuditLogService;
use App\Services\TokenizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentFacilitatorSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $merchants = $this->seedMerchants();
            $customers = Customer::factory()->count(10)->create();
            $orders = $this->seedOrders($merchants, $customers);
            $tokens = $this->seedPaymentTokens($customers);
            $this->seedTransactions($orders, $tokens);
            $this->confirmReceipts();

            if (! app(AuditLogService::class)->verifyChain()) {
                throw new RuntimeException('Audit log hash chain is invalid.');
            }

            $this->command?->info(sprintf(
                'Seeded %d merchants, %d customers, %d orders, %d payment tokens, %d transactions, %d receipts.',
                Merchant::count(),
                Customer::count(),
                Order::count(),
                PaymentToken::count(),
                Transaction::count(),
                BlockchainReceipt::count(),
            ));
        });
    }

    /**
     * @return Collection<int, Merchant>
     */
    private function seedMerchants(): Collection
    {
        $plainKeys = [
            'pk_test_'.Str::random(32),
            'pk_test_'.Str::random(32),
        ];

        $merchants = collect([
            ['name' => 'Toko Alpha', 'api_key' => $plainKeys[0]],
            ['name' => 'Toko Beta', 'api_key' => $plainKeys[1]],
        ])->map(fn (array $merchant) => Merchant::create([
            'uuid' => (string) Str::uuid(),
            'name' => $merchant['name'],
            'api_key_hash' => hash('sha256', $merchant['api_key']),
            'status' => 'active',
        ]));

        $this->command?->info('Merchant API keys (simpan untuk pengujian):');

        foreach ($merchants as $index => $merchant) {
            $this->command?->line(sprintf('  %s: %s', $merchant->name, $plainKeys[$index]));
        }

        return $merchants;
    }

    /**
     * @param  Collection<int, Merchant>  $merchants
     * @param  Collection<int, Customer>  $customers
     * @return Collection<int, Order>
     */
    private function seedOrders(Collection $merchants, Collection $customers): Collection
    {
        return collect(range(0, 19))->map(fn (int $index) => Order::factory()->create([
            'merchant_id' => $merchants[$index % $merchants->count()]->getKey(),
            'customer_id' => $customers[$index % $customers->count()]->getKey(),
        ]));
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return Collection<int, PaymentToken>
     */
    private function seedPaymentTokens(Collection $customers): Collection
    {
        $mockData = [
            ['token_type' => 'card', 'brand' => 'visa', 'number' => '4111111111111111'],
            ['token_type' => 'card', 'brand' => 'visa', 'number' => '4000056655665556'],
            ['token_type' => 'card', 'brand' => 'mastercard', 'number' => '5555555555554444'],
            ['token_type' => 'card', 'brand' => 'amex', 'number' => '378282246310005'],
            ['token_type' => 'card', 'brand' => 'discover', 'number' => '6011111111111117'],
            ['token_type' => 'bank_account', 'brand' => 'BCA', 'number' => '1234567890'],
            ['token_type' => 'bank_account', 'brand' => 'BNI', 'number' => '1234567891'],
            ['token_type' => 'bank_account', 'brand' => 'Mandiri', 'number' => '1234567892'],
            ['token_type' => 'bank_account', 'brand' => 'BRI', 'number' => '1234567893'],
            ['token_type' => 'bank_account', 'brand' => 'Permata', 'number' => '1234567894'],
            ['token_type' => 'ewallet', 'brand' => 'OVO', 'number' => '0812000000001'],
            ['token_type' => 'ewallet', 'brand' => 'DANA', 'number' => '0812000000002'],
            ['token_type' => 'ewallet', 'brand' => 'GoPay', 'number' => '0812000000003'],
            ['token_type' => 'ewallet', 'brand' => 'LinkAja', 'number' => '0812000000004'],
            ['token_type' => 'ewallet', 'brand' => 'ShopeePay', 'number' => '0812000000005'],
        ];

        $service = app(TokenizationService::class);

        return collect($mockData)->map(
            fn (array $data, int $index) => $service->tokenize(
                $customers[$index % $customers->count()],
                $data
            )
        );
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, PaymentToken>  $tokens
     */
    private function seedTransactions(Collection $orders, Collection $tokens): void
    {
        $tokenIndex = 0;

        $nextToken = function () use ($tokens, &$tokenIndex): PaymentToken {
            $token = $tokens[$tokenIndex % $tokens->count()];
            $tokenIndex++;

            return $token;
        };

        foreach ($orders->slice(0, 6) as $order) {
            Transaction::pending($order);
        }

        foreach ($orders->slice(6, 6) as $order) {
            $transaction = Transaction::pending($order);
            $transaction->proceed($nextToken());
        }

        foreach ($orders->slice(12, 6) as $order) {
            $this->expireTransaction(Transaction::pending($order));
        }

        $mixedOrder = $orders[18];

        $this->expireTransaction(Transaction::pending($mixedOrder));
        Transaction::pending($mixedOrder)->proceed($nextToken());

        $multiStatusOrder = $orders[19];

        Transaction::pending($multiStatusOrder);
        $this->expireTransaction(Transaction::pending($multiStatusOrder));
        Transaction::pending($multiStatusOrder)->proceed($nextToken());
    }

    private function expireTransaction(Transaction $transaction): void
    {
        $transaction->forceFill([
            'expires_at' => now()->subMinutes(5),
        ])->save();

        $transaction->expire();
    }

    private function confirmReceipts(): void
    {
        BlockchainReceipt::query()
            ->orderBy('id')
            ->get()
            ->each(function (BlockchainReceipt $receipt, int $index) {
                if ($index % 2 !== 0) {
                    return;
                }

                $receipt->forceFill([
                    'status' => ReceiptStatus::Confirmed,
                    'tx_hash' => '0x'.bin2hex(random_bytes(32)),
                    'block_number' => random_int(1_000_000, 9_000_000),
                    'attempts' => 1,
                    'next_attempt_at' => null,
                    'submitted_at' => now()->subMinutes(5),
                    'confirmed_at' => now()->subMinutes(4),
                ])->save();
            });
    }
}
