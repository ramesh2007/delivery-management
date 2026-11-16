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
        if (!Schema::hasTable('mis_sla_configs')) {
            Schema::create('mis_sla_configs', function (Blueprint $table) {
                $table->id();
                $table->string('sla_type', 50)->unique();
                $table->string('display_name', 100);
                $table->integer('duration_minutes');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed default Normal (4h) and Express (1h)
            DB::table('mis_sla_configs')->insert([
                [
                    'sla_type' => 'normal',
                    'display_name' => 'Normal Delivery (4 hrs)',
                    'duration_minutes' => 240,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sla_type' => 'express',
                    'display_name' => 'Express Delivery (1 hr)',
                    'duration_minutes' => 60,
                    'is_active' => true,
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
        Schema::dropIfExists('mis_sla_configs');
    }
};

