<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('reference', 50)->unique();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_token_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('IDR');
            $table->enum('status', ['pending', 'proceed', 'expired'])->default('pending');
            $table->unsignedBigInteger('amount_minor')->storedAs('CAST(ROUND(amount * 100, 0) AS UNSIGNED)');
            $table->string('gateway_ref', 100)->nullable();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index('order_id', 'idx_trx_order');
            $table->index(['status', 'expires_at'], 'idx_trx_status_expires');
            $table->index(['merchant_id', 'created_at'], 'idx_trx_merchant_created');
            $table->foreign('merchant_id', 'fk_trx_merchant')->references('id')->on('merchants');
            $table->foreign('order_id', 'fk_trx_order')->references('id')->on('orders');
            $table->foreign('payment_token_id', 'fk_trx_token')->references('id')->on('payment_tokens');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `transactions` ADD CONSTRAINT `chk_trx_amount` CHECK (`amount` > 0)');
            DB::statement(<<<'SQL'
                ALTER TABLE `transactions` ADD CONSTRAINT `chk_trx_state` CHECK (
                    (`status` = 'pending' AND `paid_at` IS NULL AND `expired_at` IS NULL) OR
                    (`status` = 'proceed' AND `paid_at` IS NOT NULL AND `payment_token_id` IS NOT NULL
                        AND `expired_at` IS NULL AND `gateway_ref` IS NOT NULL AND `gateway_ref` <> '') OR
                    (`status` = 'expired' AND `paid_at` IS NULL AND `expired_at` IS NOT NULL)
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
