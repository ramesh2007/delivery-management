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
        if (!Schema::hasTable('order_installations')) {
            Schema::create('order_installations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
                $table->string('installation_type')->nullable();
                $table->string('installation_level')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('order_installations', function (Blueprint $table) {
                if (!Schema::hasColumn('order_installations', 'installation_level')) {
                    $table->string('installation_level')->nullable()->after('installation_type');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_installations');
    }
};
