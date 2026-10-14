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
        // 1. Create installation_images table
        if (!Schema::hasTable('installation_images')) {
            Schema::create('installation_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('cascade');
                $table->string('order_number')->nullable();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->onDelete('cascade');
                $table->foreignId('order_installation_id')->nullable()->constrained('order_installations')->onDelete('cascade');
                $table->text('before_install_image')->nullable();
                $table->text('after_install_image')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 2. Ensure order_installations table has notes, completed_at, and images if missing
        if (Schema::hasTable('order_installations')) {
            Schema::table('order_installations', function (Blueprint $table) {
                if (!Schema::hasColumn('order_installations', 'notes')) {
                    $table->text('notes')->nullable()->after('is_scheduled_assigned');
                }
                if (!Schema::hasColumn('order_installations', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('notes');
                }
                if (!Schema::hasColumn('order_installations', 'images')) {
                    $table->json('images')->nullable()->after('completed_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installation_images');

        if (Schema::hasTable('order_installations')) {
            Schema::table('order_installations', function (Blueprint $table) {
                if (Schema::hasColumn('order_installations', 'images')) {
                    $table->dropColumn('images');
                }
                if (Schema::hasColumn('order_installations', 'completed_at')) {
                    $table->dropColumn('completed_at');
                }
                if (Schema::hasColumn('order_installations', 'notes')) {
                    $table->dropColumn('notes');
                }
            });
        }
    }
};
