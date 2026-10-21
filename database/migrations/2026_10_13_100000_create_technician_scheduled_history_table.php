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
        // Minimal, optimized history log table for technician reschedules
        if (!Schema::hasTable('technician_scheduled_history')) {
            Schema::create('technician_scheduled_history', function (Blueprint $table) {
                $table->id();

                // Foreign key referencing master technician schedule
                $table->foreignId('technician_scheduled_id')
                    ->constrained('technician_scheduled')
                    ->onDelete('cascade');

                // Previous technician details
                $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('technician_name')->nullable();

                // Previous schedule slot
                $table->date('scheduled_date')->nullable()->index();
                $table->string('start_time')->nullable();
                $table->string('end_time')->nullable();
                $table->integer('taken_time_in_mins')->nullable();

                // Previous status & notes
                $table->string('status')->nullable();
                $table->text('notes')->nullable();

                // Reschedule reason & who initiated it
                $table->text('reschedule_reason')->nullable();
                $table->foreignId('rescheduled_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_scheduled_history');
    }
};
