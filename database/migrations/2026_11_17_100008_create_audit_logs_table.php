<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->char('prev_hash', 64);
            $table->char('record_hash', 64)->unique();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index('transaction_id', 'idx_audit_trx');
            $table->foreign('transaction_id', 'fk_audit_trx')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
