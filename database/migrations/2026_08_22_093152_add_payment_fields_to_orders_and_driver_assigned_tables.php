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
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'payment_method')) {
                    $table->string('payment_method')->nullable()->after('total_amount');
                }
                if (!Schema::hasColumn('orders', 'payment_status')) {
                    $table->string('payment_status')->nullable()->after('payment_method');
                }
                if (!Schema::hasColumn('orders', 'collected_amount')) {
                    $table->decimal('collected_amount', 10, 2)->nullable()->after('payment_status');
                }
            });
        }

        if (Schema::hasTable('orders_driver_assigned')) {
            Schema::table('orders_driver_assigned', function (Blueprint $table) {
                if (!Schema::hasColumn('orders_driver_assigned', 'payment_method')) {
                    $table->string('payment_method')->nullable()->after('driver_status');
                }
                if (!Schema::hasColumn('orders_driver_assigned', 'payment_status')) {
                    $table->string('payment_status')->nullable()->after('payment_method');
                }
                if (!Schema::hasColumn('orders_driver_assigned', 'collected_amount')) {
                    $table->decimal('collected_amount', 10, 2)->nullable()->after('payment_status');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['payment_method', 'payment_status', 'collected_amount']);
            });
        }

        if (Schema::hasTable('orders_driver_assigned')) {
            Schema::table('orders_driver_assigned', function (Blueprint $table) {
                $table->dropColumn(['payment_method', 'payment_status', 'collected_amount']);
            });
        }
    }
};
