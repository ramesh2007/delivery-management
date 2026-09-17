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
        Schema::table('order_installations', function (Blueprint $table) {
            if (!Schema::hasColumn('order_installations', 'is_scheduled_assigned')) {
                $table->boolean('is_scheduled_assigned')->default(false)->after('installation_level');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_installations', function (Blueprint $table) {
            if (Schema::hasColumn('order_installations', 'is_scheduled_assigned')) {
                $table->dropColumn('is_scheduled_assigned');
            }
        });
    }
};
