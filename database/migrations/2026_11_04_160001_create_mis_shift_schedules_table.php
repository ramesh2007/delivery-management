<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('mis_shift_schedules')) {
            Schema::create('mis_shift_schedules', function (Blueprint $table) {
                $table->id();
                $table->tinyInteger('day_of_week')->unique(); // 0 = Sunday, 1 = Monday, ..., 5 = Friday, 6 = Saturday
                $table->string('day_name', 15);
                $table->boolean('is_working_day')->default(true);
                $table->boolean('is_half_day')->default(false);
                $table->time('shift_start')->default('09:00:00');
                $table->time('shift_end')->default('18:00:00');
                $table->timestamps();
            });

            // Seed default weekly working schedule for Qatar/GCC
            // Sunday - Thursday: 09:00 - 18:00 (Full day)
            // Friday: Closed / Holiday
            // Saturday: 09:00 - 13:00 (Half day)
            DB::table('mis_shift_schedules')->insert([
                [
                    'day_of_week' => 0,
                    'day_name' => 'Sunday',
                    'is_working_day' => true,
                    'is_half_day' => false,
                    'shift_start' => '09:00:00',
                    'shift_end' => '18:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day_of_week' => 1,
                    'day_name' => 'Monday',
                    'is_working_day' => true,
                    'is_half_day' => false,
                    'shift_start' => '09:00:00',
                    'shift_end' => '18:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day_of_week' => 2,
                    'day_name' => 'Tuesday',
                    'is_working_day' => true,
                    'is_half_day' => false,
                    'shift_start' => '09:00:00',
                    'shift_end' => '18:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day_of_week' => 3,
                    'day_name' => 'Wednesday',
                    'is_working_day' => true,
                    'is_half_day' => false,
                    'shift_start' => '09:00:00',
                    'shift_end' => '18:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day_of_week' => 4,
                    'day_name' => 'Thursday',
                    'is_working_day' => true,
                    'is_half_day' => false,
                    'shift_start' => '09:00:00',
                    'shift_end' => '18:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day_of_week' => 5,
                    'day_name' => 'Friday',
                    'is_working_day' => false,
                    'is_half_day' => false,
                    'shift_start' => '00:00:00',
                    'shift_end' => '00:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day_of_week' => 6,
                    'day_name' => 'Saturday',
                    'is_working_day' => true,
                    'is_half_day' => true,
                    'shift_start' => '09:00:00',
                    'shift_end' => '13:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mis_shift_schedules');
    }
};

