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
        if (!Schema::hasTable('installation_images')) {
            Schema::create('installation_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('order_number')->nullable();
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->unsignedBigInteger('order_installation_id')->nullable();
                $table->string('before_install_image')->nullable();
                $table->string('after_install_image')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installation_images');
    }
};
