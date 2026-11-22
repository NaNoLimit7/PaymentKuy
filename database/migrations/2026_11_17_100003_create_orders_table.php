<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('order_number', 50);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('IDR');
            $table->timestamps();

            $table->unique(['merchant_id', 'order_number'], 'uq_orders_merchant_number');
            $table->foreign('merchant_id', 'fk_orders_merchant')->references('id')->on('merchants');
            $table->foreign('customer_id', 'fk_orders_customer')->references('id')->on('customers');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `orders` ADD CONSTRAINT `chk_orders_amount` CHECK (`amount` > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
