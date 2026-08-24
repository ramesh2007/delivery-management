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
        if (!Schema::hasTable('packer_verifications')) {
            Schema::create('packer_verifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
                $table->foreignId('packer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('packer_name')->nullable();
                $table->string('scanned_barcode');
                $table->boolean('is_verified')->default(true);
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'bag_count')) {
                $table->integer('bag_count')->default(0)->after('status');
            }
            if (!Schema::hasColumn('orders', 'packed_by')) {
                $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete()->after('assigned_at');
                $table->string('packed_user_name')->nullable()->after('packed_by');
                $table->timestamp('packed_at')->nullable()->after('packed_user_name');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'is_packer_verified')) {
                $table->boolean('is_packer_verified')->default(false)->after('status');
                $table->foreignId('packer_verified_by')->nullable()->constrained('users')->nullOnDelete()->after('is_packer_verified');
                $table->string('packer_verified_user_name')->nullable()->after('packer_verified_by');
                $table->timestamp('packer_verified_at')->nullable()->after('packer_verified_user_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packer_verifications');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['bag_count']);
            if (Schema::hasColumn('orders', 'packed_by')) {
                $table->dropForeign(['packed_by']);
                $table->dropColumn(['packed_by', 'packed_user_name', 'packed_at']);
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['packer_verified_by']);
            $table->dropColumn(['is_packer_verified', 'packer_verified_by', 'packer_verified_user_name', 'packer_verified_at']);
        });
    }
};
