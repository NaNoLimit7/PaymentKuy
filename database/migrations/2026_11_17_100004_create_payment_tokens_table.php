<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_tokens', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('customer_id');
            $table->enum('token_type', ['card', 'bank_account', 'ewallet']);
            $table->string('brand', 30)->nullable();
            $table->char('last4', 4);
            $table->char('fingerprint', 64);
            $table->text('encrypted_payload');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('fingerprint', 'idx_tokens_fingerprint');
            $table->foreign('customer_id', 'fk_tokens_customer')->references('id')->on('customers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_tokens');
    }
};
