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
        if (Schema::hasTable('installation_images') && Schema::hasColumn('installation_images', 'images')) {
            Schema::table('installation_images', function (Blueprint $table) {
                $table->dropColumn('images');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('installation_images') && !Schema::hasColumn('installation_images', 'images')) {
            Schema::table('installation_images', function (Blueprint $table) {
                $table->json('images')->nullable()->after('after_install_image');
            });
        }
    }
};
