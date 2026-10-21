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
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('order_items', 'gift_message')) {
                    $table->text('gift_message')->nullable()->after('gift_wrap');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'is_bag_verified')) {
                    $table->boolean('is_bag_verified')->default(false)->after('bag_count');
                }
            });
        }

        if (Schema::hasTable('orders_driver_assigned')) {
            Schema::table('orders_driver_assigned', function (Blueprint $table) {
                if (!Schema::hasColumn('orders_driver_assigned', 'is_bag_verified')) {
                    $table->boolean('is_bag_verified')->default(false)->after('driver_status');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'gift_message')) {
                    $table->dropColumn('gift_message');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'is_bag_verified')) {
                    $table->dropColumn('is_bag_verified');
                }
            });
        }

        if (Schema::hasTable('orders_driver_assigned')) {
            Schema::table('orders_driver_assigned', function (Blueprint $table) {
                if (Schema::hasColumn('orders_driver_assigned', 'is_bag_verified')) {
                    $table->dropColumn('is_bag_verified');
                }
            });
        }
    }
};
