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
        Schema::create('orders_driver_assigned', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->string('order_number')->index();
            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('driver_name')->nullable();
            $table->string('zone')->nullable();
            $table->string('order_status')->default('packed');
            $table->enum('driver_status', [
                'assigned',
                'accepted',
                'started',
                'delivered',
                'cancelled',
                'refund',
                'exchange'
            ])->default('assigned');
            
            // Timestamps for each status stage
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refund_at')->nullable();
            $table->timestamp('exchange_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_driver_assigned');
    }
};
