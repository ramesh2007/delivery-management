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
        if (!Schema::hasTable('shopify_sync_logs')) {
            Schema::create('shopify_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event_type')->default('webhook_order'); // webhook_order_create, webhook_order_update, cron_sync, manual_sync, etc.
                $table->string('topic')->nullable(); // e.g. orders/create, orders/updated, orders/paid
                $table->string('shop_domain')->nullable();
                $table->string('shopify_order_id')->nullable();
                $table->string('order_number')->nullable();
                $table->foreignId('local_order_id')->nullable()->constrained('orders')->onDelete('set null');
                $table->enum('status', ['success', 'failed', 'pending', 'skipped'])->default('pending');
                $table->text('error_message')->nullable();
                $table->json('error_details')->nullable();
                $table->json('payload')->nullable();
                $table->json('headers')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->unsignedInteger('items_count')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->timestamps();

                $table->index(['event_type', 'status']);
                $table->index('order_number');
                $table->index('shopify_order_id');
                $table->index('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_sync_logs');
    }
};
