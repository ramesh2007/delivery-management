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
        if (!Schema::hasTable('technician_scheduled')) {
            Schema::create('technician_scheduled', function (Blueprint $table) {
                $table->id();

                // Order and Item references
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('order_number')->nullable()->index();
                $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
                $table->foreignId('order_installation_id')->nullable()->constrained('order_installations')->nullOnDelete();

                // Technician details
                $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('technician_name')->nullable();

                // Scheduled date & time
                $table->date('scheduled_date')->nullable()->index();
                $table->string('scheduled_time')->nullable();
                $table->dateTime('scheduled_at')->nullable()->index();

                // Customer installation location details
                $table->string('zone')->nullable()->index();
                $table->string('building')->nullable();
                $table->string('unit')->nullable();
                $table->string('street')->nullable();
                $table->text('address')->nullable();

                // Customer details
                $table->string('customer_name')->nullable();
                $table->string('customer_phone')->nullable();

                // Status & Tracking
                $table->string('status')->default('scheduled')->index(); // scheduled, in_progress, completed, cancelled, rescheduled
                $table->text('notes')->nullable();
                $table->json('images')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_scheduled');
    }
};
