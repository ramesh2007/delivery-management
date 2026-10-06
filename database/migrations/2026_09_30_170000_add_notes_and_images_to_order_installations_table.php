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
        if (Schema::hasTable('order_installations')) {
            Schema::table('order_installations', function (Blueprint $table) {
                if (!Schema::hasColumn('order_installations', 'notes')) {
                    $table->text('notes')->nullable()->after('status');
                }
                if (!Schema::hasColumn('order_installations', 'images')) {
                    $table->longText('images')->nullable()->after('notes');
                }
                if (!Schema::hasColumn('order_installations', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('images');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_installations')) {
            Schema::table('order_installations', function (Blueprint $table) {
                if (Schema::hasColumn('order_installations', 'completed_at')) {
                    $table->dropColumn('completed_at');
                }
                if (Schema::hasColumn('order_installations', 'images')) {
                    $table->dropColumn('images');
                }
                if (Schema::hasColumn('order_installations', 'notes')) {
                    $table->dropColumn('notes');
                }
            });
        }
    }
};
