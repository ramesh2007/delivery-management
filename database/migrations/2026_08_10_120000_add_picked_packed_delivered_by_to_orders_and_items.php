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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete()->after('assigned_at');
            $table->string('delivered_user_name')->nullable()->after('delivered_by');
            $table->timestamp('delivered_at')->nullable()->after('delivered_user_name');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete()->after('assigned_at');
            $table->string('picked_user_name')->nullable()->after('picked_by');

            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete()->after('picked_at');
            $table->string('packed_user_name')->nullable()->after('packed_by');

            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete()->after('packed_at');
            $table->string('delivered_user_name')->nullable()->after('delivered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivered_by']);
            $table->dropColumn(['delivered_by', 'delivered_user_name', 'delivered_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['picked_by']);
            $table->dropColumn(['picked_by', 'picked_user_name']);

            $table->dropForeign(['packed_by']);
            $table->dropColumn(['packed_by', 'packed_user_name']);

            $table->dropForeign(['delivered_by']);
            $table->dropColumn(['delivered_by', 'delivered_user_name']);
        });
    }
};
