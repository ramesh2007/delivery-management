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
        Schema::create('cc_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 50)->unique();
            $table->foreignId('customer_id')->constrained('cc_customers')->onDelete('cascade');
            $table->foreignId('conversation_id')->nullable()->constrained('cc_conversations')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('order_number', 100)->nullable()->index();
            $table->string('ticket_type', 50)->index(); // return, cancellation, replacement, complaint, delivery, payment, installation
            $table->string('category', 100)->nullable()->index();
            $table->string('subject', 255)->nullable();
            $table->longText('description')->nullable();
            $table->string('priority', 20)->default('normal')->index(); // low, normal, high, urgent
            $table->string('status', 30)->default('new')->index(); // new, open, in_progress, waiting, resolved, rejected, closed
            $table->unsignedBigInteger('workflow_id')->nullable()->index(); // FK to cc_workflows
            $table->unsignedBigInteger('current_step_id')->nullable()->index(); // Current workflow step
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('due_at')->nullable()->index(); // SLA deadline
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cc_tickets');
    }
};
