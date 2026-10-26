<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('flag_images')) {
            Schema::create('flag_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('order_number')->nullable()->index();
                $table->unsignedBigInteger('order_item_id')->nullable()->index();
                $table->string('line_item_id')->nullable()->index();
                $table->unsignedBigInteger('discrepancy_id')->nullable()->index();
                $table->text('image_url')->nullable();
                $table->text('photo_url')->nullable();
                $table->string('issue_type')->nullable();
                $table->text('comment')->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_name')->nullable();
                $table->timestamps();
            });
        }

        // Backfill any existing discrepancy images against order_id and line_item_id
        if (Schema::hasTable('order_item_discrepancies')) {
            try {
                $existingDiscrepancies = DB::table('order_item_discrepancies')
                    ->leftJoin('order_items', 'order_item_discrepancies.order_item_id', '=', 'order_items.id')
                    ->leftJoin('orders', 'order_item_discrepancies.order_id', '=', 'orders.id')
                    ->whereNotNull('order_item_discrepancies.photo_url')
                    ->where('order_item_discrepancies.photo_url', '!=', '')
                    ->select([
                        'order_item_discrepancies.id as discrepancy_id',
                        'order_item_discrepancies.order_id',
                        'orders.order_number',
                        'order_item_discrepancies.order_item_id',
                        'order_items.line_item_id',
                        'order_item_discrepancies.photo_url',
                        'order_item_discrepancies.issue_type',
                        'order_item_discrepancies.comment',
                        'order_item_discrepancies.user_id',
                        'order_item_discrepancies.user_name',
                        'order_item_discrepancies.created_at',
                        'order_item_discrepancies.updated_at',
                    ])
                    ->get();

                foreach ($existingDiscrepancies as $row) {
                    $exists = DB::table('flag_images')
                        ->where('discrepancy_id', $row->discrepancy_id)
                        ->where(function ($q) use ($row) {
                            $q->where('image_url', $row->photo_url)
                              ->orWhere('photo_url', $row->photo_url);
                        })
                        ->exists();

                    if (!$exists) {
                        DB::table('flag_images')->insert([
                            'order_id' => $row->order_id,
                            'order_number' => $row->order_number,
                            'order_item_id' => $row->order_item_id,
                            'line_item_id' => $row->line_item_id,
                            'discrepancy_id' => $row->discrepancy_id,
                            'image_url' => $row->photo_url,
                            'photo_url' => $row->photo_url,
                            'issue_type' => $row->issue_type,
                            'comment' => $row->comment,
                            'user_id' => $row->user_id,
                            'user_name' => $row->user_name,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => $row->updated_at ?? now(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Ignore backfill errors in environments where related tables may be empty
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flag_images');
    }
};
