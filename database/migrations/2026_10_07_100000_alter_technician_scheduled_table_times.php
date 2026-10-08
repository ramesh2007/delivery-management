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
        Schema::table('technician_scheduled', function (Blueprint $table) {
            // Rename scheduled_time to start_time if scheduled_time exists
            if (Schema::hasColumn('technician_scheduled', 'scheduled_time') && !Schema::hasColumn('technician_scheduled', 'start_time')) {
                $table->renameColumn('scheduled_time', 'start_time');
            } elseif (!Schema::hasColumn('technician_scheduled', 'start_time')) {
                $table->string('start_time')->nullable()->after('scheduled_date');
            }

            // Add end_time column
            if (!Schema::hasColumn('technician_scheduled', 'end_time')) {
                $table->string('end_time')->nullable()->after('start_time');
            }

            // Add taken_time_in_mins column
            if (!Schema::hasColumn('technician_scheduled', 'taken_time_in_mins')) {
                $table->integer('taken_time_in_mins')->nullable()->after('end_time');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('technician_scheduled', function (Blueprint $table) {
            if (Schema::hasColumn('technician_scheduled', 'taken_time_in_mins')) {
                $table->dropColumn('taken_time_in_mins');
            }

            if (Schema::hasColumn('technician_scheduled', 'end_time')) {
                $table->dropColumn('end_time');
            }

            if (Schema::hasColumn('technician_scheduled', 'start_time') && !Schema::hasColumn('technician_scheduled', 'scheduled_time')) {
                $table->renameColumn('start_time', 'scheduled_time');
            }
        });
    }
};
