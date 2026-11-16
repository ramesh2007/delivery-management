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
        Schema::create('cc_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('cc_customers')->onDelete('cascade');
            $table->string('channel', 30)->index(); // whatsapp, instagram, facebook
            $table->string('channel_account_id', 150)->nullable()->index();
            $table->string('external_conversation_id', 255)->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('open')->index(); // open, pending, resolved, closed
            $table->string('priority', 20)->default('normal')->index(); // low, normal, high, urgent
            $table->string('subject', 255)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->text('last_message_preview')->nullable();
            $table->integer('unread_count')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cc_conversations');
    }
};
