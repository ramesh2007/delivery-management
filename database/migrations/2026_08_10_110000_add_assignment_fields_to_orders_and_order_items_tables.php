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
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('assigned_user_name')->nullable()->after('assigned_to');
            $table->timestamp('assigned_at')->nullable()->after('assigned_user_name');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('assigned_user_name')->nullable()->after('assigned_to');
            $table->timestamp('assigned_at')->nullable()->after('assigned_user_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropColumn(['assigned_to', 'assigned_user_name', 'assigned_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropColumn(['assigned_to', 'assigned_user_name', 'assigned_at']);
        });
    }
};
