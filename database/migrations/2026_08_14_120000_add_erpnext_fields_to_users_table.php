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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->unique()->after('email');
            }
            if (!Schema::hasColumn('users', 'erpnext_user_id')) {
                $table->string('erpnext_user_id')->nullable()->after('username');
            }
            if (!Schema::hasColumn('users', 'erpnext_api_key')) {
                $table->string('erpnext_api_key')->nullable()->after('erpnext_user_id');
            }
            if (!Schema::hasColumn('users', 'erpnext_api_secret')) {
                $table->text('erpnext_api_secret')->nullable()->after('erpnext_api_key');
            }
            if (!Schema::hasColumn('users', 'erpnext_token')) {
                $table->text('erpnext_token')->nullable()->after('erpnext_api_secret');
            }
            if (!Schema::hasColumn('users', 'erpnext_synced_at')) {
                $table->timestamp('erpnext_synced_at')->nullable()->after('erpnext_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('users', 'username')) {
                $columnsToDrop[] = 'username';
            }
            if (Schema::hasColumn('users', 'erpnext_user_id')) {
                $columnsToDrop[] = 'erpnext_user_id';
            }
            if (Schema::hasColumn('users', 'erpnext_api_key')) {
                $columnsToDrop[] = 'erpnext_api_key';
            }
            if (Schema::hasColumn('users', 'erpnext_api_secret')) {
                $columnsToDrop[] = 'erpnext_api_secret';
            }
            if (Schema::hasColumn('users', 'erpnext_token')) {
                $columnsToDrop[] = 'erpnext_token';
            }
            if (Schema::hasColumn('users', 'erpnext_synced_at')) {
                $columnsToDrop[] = 'erpnext_synced_at';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
