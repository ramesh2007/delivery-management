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
        Schema::create('cc_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('cc_conversations')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('cc_customers')->onDelete('cascade');
            $table->string('direction', 10)->index(); // inbound, outbound
            $table->string('message_type', 30)->default('text')->index(); // text, image, video, audio, document, location, template
            $table->string('external_message_id', 255)->nullable()->index(); // Meta message ID
            $table->foreignId('reply_to_message_id')->nullable()->constrained('cc_messages')->nullOnDelete();
            $table->string('sender_type', 20)->index(); // customer, agent, system
            $table->unsignedBigInteger('sender_id')->nullable()->index(); // User ID if agent
            $table->longText('message_body')->nullable();
            $table->text('attachment_url')->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime_type', 100)->nullable();
            $table->string('delivery_status', 30)->default('pending')->index(); // pending, sent, delivered, read, failed
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cc_messages');
    }
};
