<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blockchain_receipts', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('transaction_id')->unique();
            $table->unsignedBigInteger('audit_log_id');
            $table->string('contract_order_id', 50)->unique();
            $table->char('receipt_hash', 66);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('settlement_time');
            $table->string('gateway_ref', 100);
            $table->char('currency', 3);
            $table->string('network', 20)->default('sepolia');
            $table->char('contract_address', 42)->nullable();
            $table->char('tx_hash', 66)->nullable()->unique();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->enum('status', ['pending', 'submitted', 'confirmed', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at'], 'idx_receipt_queue');
            $table->foreign('transaction_id', 'fk_receipt_trx')->references('id')->on('transactions');
            $table->foreign('audit_log_id', 'fk_receipt_audit')->references('id')->on('audit_logs');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blockchain_receipts` ADD CONSTRAINT `chk_receipt_amount` CHECK (`amount_minor` > 0)');
            DB::statement('ALTER TABLE `blockchain_receipts` ADD CONSTRAINT `chk_receipt_settlement` CHECK (`settlement_time` > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_receipts');
    }
};
