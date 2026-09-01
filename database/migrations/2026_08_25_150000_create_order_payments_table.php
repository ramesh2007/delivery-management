<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('order_payments')) {
            Schema::create('order_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('shopify_order_id')->nullable();
                $table->string('payment_method')->nullable();
                $table->string('payment_status')->nullable();
                $table->decimal('paid_amount', 10, 2)->default(0.00);
                $table->decimal('total_price', 10, 2)->default(0.00);
                $table->decimal('total_outstanding', 10, 2)->default(0.00);
                $table->string('currency', 10)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('shopify_created_at')->nullable();
                $table->timestamp('shopify_updated_at')->nullable();
                $table->json('raw_payment_details')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
