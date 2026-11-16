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
        if (!Schema::hasTable('mis_order_slas')) {
            Schema::create('mis_order_slas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained('orders')->onDelete('cascade');
                $table->string('order_number', 100)->index();
                $table->string('sla_type', 30)->default('normal'); // normal, express
                $table->integer('sla_duration_minutes')->default(240);
                $table->dateTime('order_placed_at')->nullable();
                $table->dateTime('sla_start_at')->nullable();
                $table->dateTime('sla_due_at')->nullable()->index();
                $table->string('sla_status', 40)->default('within_sla')->index();
                // within_sla, near_breach, breached, completed_within_sla, completed_breached
                $table->boolean('is_breached')->default(false)->index();
                $table->integer('breached_by_minutes')->nullable();
                $table->integer('total_tat_minutes')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mis_order_slas');
    }
};

