<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('idempotency_key', 128);
            $table->string('endpoint', 150);
            $table->char('request_hash', 64);
            $table->enum('status', ['processing', 'completed'])->default('processing');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamps();

            $table->unique(['merchant_id', 'idempotency_key', 'endpoint'], 'uq_idem');
            $table->index('expires_at', 'idx_idem_expires');
            $table->foreign('merchant_id', 'fk_idem_merchant')->references('id')->on('merchants');
            $table->foreign('transaction_id', 'fk_idem_trx')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
