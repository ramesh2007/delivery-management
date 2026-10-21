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
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'is_vs_verified')) {
                $table->boolean('is_vs_verified')->default(false)->after('status');
                $table->foreignId('vs_verified_by')->nullable()->constrained('users')->nullOnDelete()->after('is_vs_verified');
                $table->string('vs_verified_user_name')->nullable()->after('vs_verified_by');
                $table->timestamp('vs_verified_at')->nullable()->after('vs_verified_user_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'vs_verified_by')) {
                $table->dropForeign(['vs_verified_by']);
            }
            $columnsToDrop = array_filter(
                ['is_vs_verified', 'vs_verified_by', 'vs_verified_user_name', 'vs_verified_at'],
                fn($col) => Schema::hasColumn('order_items', $col)
            );
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
