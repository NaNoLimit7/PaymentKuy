<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_state_histories', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->enum('from_status', ['pending', 'proceed', 'expired'])->nullable();
            $table->enum('to_status', ['pending', 'proceed', 'expired']);
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('transaction_id', 'idx_hist_trx');
            $table->foreign('transaction_id', 'fk_hist_trx')->references('id')->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_state_histories');
    }
};
