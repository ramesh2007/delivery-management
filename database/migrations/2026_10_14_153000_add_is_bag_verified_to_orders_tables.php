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
        if (Schema::hasTable('orders_driver_assigned') && !Schema::hasColumn('orders_driver_assigned', 'is_bag_verified')) {
            Schema::table('orders_driver_assigned', function (Blueprint $table) {
                $table->boolean('is_bag_verified')->default(false)->after('driver_status');
            });
        }

        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'is_bag_verified')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('is_bag_verified')->default(false)->after('bag_count');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders_driver_assigned') && Schema::hasColumn('orders_driver_assigned', 'is_bag_verified')) {
            Schema::table('orders_driver_assigned', function (Blueprint $table) {
                $table->dropColumn('is_bag_verified');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'is_bag_verified')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('is_bag_verified');
            });
        }
    }
};
