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
                if (!Schema::hasColumn('order_installations', 'status')) {
                    $table->string('status')->default('pending')->nullable()->after('installation_level')->index();
                }
                if (!Schema::hasColumn('order_installations', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (!Schema::hasColumn('order_installations', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable();
                }
                if (!Schema::hasColumn('order_installations', 'images')) {
                    $table->json('images')->nullable();
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
                if (Schema::hasColumn('order_installations', 'status')) {
                    $table->dropColumn('status');
                }
            });
        }
    }
};
