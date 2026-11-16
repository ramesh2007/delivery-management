<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('mis_benchmark')) {
            Schema::create('mis_benchmark', function (Blueprint $table) {
                $table->id();
                $table->string('role', 50)->unique(); // picker, packer, packed_to_driver, driver
                $table->string('stage', 50)->unique(); // picking, packing, packed_to_driver, delivery
                $table->string('display_name', 100);
                $table->integer('benchmark_minutes')->default(0);
                $table->boolean('is_freeze_active')->default(false);
                $table->time('freeze_start')->nullable();
                $table->time('freeze_end')->nullable();
                $table->json('freeze_windows')->nullable(); // Optional multiple time-freezing ranges per role/stage
                $table->tinyInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::table('mis_benchmark')->insert([
                [
                    'role' => 'picker',
                    'stage' => 'picking',
                    'display_name' => 'Picking Benchmark (min)',
                    'benchmark_minutes' => 30,
                    'is_freeze_active' => false,
                    'freeze_start' => null,
                    'freeze_end' => null,
                    'freeze_windows' => null,
                    'sort_order' => 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'role' => 'packer',
                    'stage' => 'packing',
                    'display_name' => 'Packing Benchmark (min)',
                    'benchmark_minutes' => 20,
                    'is_freeze_active' => false,
                    'freeze_start' => null,
                    'freeze_end' => null,
                    'freeze_windows' => null,
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'role' => 'packed_to_driver',
                    'stage' => 'packed_to_driver',
                    'display_name' => 'Packed → Driver Benchmark (min)',
                    'benchmark_minutes' => 30,
                    'is_freeze_active' => false,
                    'freeze_start' => null,
                    'freeze_end' => null,
                    'freeze_windows' => null,
                    'sort_order' => 3,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'role' => 'driver',
                    'stage' => 'delivery',
                    'display_name' => 'Delivery Benchmark (min)',
                    'benchmark_minutes' => 120,
                    'is_freeze_active' => false,
                    'freeze_start' => null,
                    'freeze_end' => null,
                    'freeze_windows' => null,
                    'sort_order' => 4,
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
        Schema::dropIfExists('mis_benchmark');
    }
};

