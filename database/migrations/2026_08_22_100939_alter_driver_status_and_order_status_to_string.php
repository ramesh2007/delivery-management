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
        try {
            DB::statement("ALTER TABLE `orders_driver_assigned` MODIFY `driver_status` VARCHAR(255) NULL DEFAULT 'assigned'");
        } catch (\Exception $e) {
            // Ignore if column already string or table not exists
        }

        try {
            DB::statement("ALTER TABLE `orders` MODIFY `status` VARCHAR(255) NULL DEFAULT 'pending'");
        } catch (\Exception $e) {
            // Ignore if column already string or table not exists
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
