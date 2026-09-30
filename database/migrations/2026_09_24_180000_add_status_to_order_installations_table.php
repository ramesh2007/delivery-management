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
                    $table->string('status')->nullable()->default('scheduled')->after('installation_level');
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
