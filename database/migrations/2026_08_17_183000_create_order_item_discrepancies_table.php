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
        Schema::create('order_item_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('issue_type')->default('damaged'); // damaged, missing, wrong_item, expired, other
            $table->text('comment')->nullable();
            $table->string('status')->default('open'); // open, in_review, resolved, rejected
            $table->string('photo_url')->nullable();
            $table->timestamps();
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'is_flagged')) {
                $table->boolean('is_flagged')->default(false)->after('status');
                $table->string('flag_reason')->nullable()->after('is_flagged');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_discrepancies');

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'is_flagged')) {
                $table->dropColumn(['is_flagged', 'flag_reason']);
            }
        });
    }
};
