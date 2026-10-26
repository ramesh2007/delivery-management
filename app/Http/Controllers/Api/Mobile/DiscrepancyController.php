<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\FlagImage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;



class DiscrepancyController extends Controller
{

    public function flagItemDiscrepancy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number'  => 'required',
            'order_item_id' => 'nullable|integer',
            'line_item_id'  => 'nullable',
            'barcode'       => 'nullable|string',
            'product_code'  => 'nullable|string',
            'user_id'       => 'nullable|integer',
            'user_name'     => 'nullable|string|max:255',
            'issue_type'    => 'nullable|string|max:100',
            'comment'       => 'required|string',

            // Optional single image.
            'photo'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',

            // Optional multiple images, maximum 10.
            'photos'        => 'nullable|array|max:10',
            'photos.*'      => 'image|mimes:jpg,jpeg,png,webp|max:10240',

            // Optional existing image URL(s).
            'photo_url'     => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $storedFiles = [];

        try {
            /*
            |--------------------------------------------------------------------------
            | 1. Find order using order_number
            |--------------------------------------------------------------------------
            */
            $order = Order::where(
                'order_number',
                $request->input('order_number')
            )->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found with the provided order number.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Locate the order item
            |--------------------------------------------------------------------------
            */
            $itemQuery = OrderItem::where('order_id', $order->id);

            if ($request->filled('order_item_id')) {
                $itemQuery->where('id', $request->input('order_item_id'));
            } elseif ($request->filled('line_item_id')) {
                $itemQuery->where(
                    'line_item_id',
                    $request->input('line_item_id')
                );
            } elseif ($request->filled('barcode')) {
                $itemQuery->where('barcode', $request->input('barcode'));
            } elseif ($request->filled('product_code')) {
                $itemQuery->where(
                    'product_code',
                    $request->input('product_code')
                );
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Provide order_item_id, line_item_id, barcode, or product_code to identify the order item.',
                ], 422);
            }

            $orderItem = $itemQuery->first();

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order item not found for the provided order number and item identifier.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | 3. Resolve user details
            |--------------------------------------------------------------------------
            */
            $userId = $request->input('user_id') ?: Auth::id();

            $userName = $request->input('user_name');

            if (!$userName && $userId) {
                $userObj = \App\Models\User::find($userId);
                $userName = $userObj?->name;
            }

            $userName = $userName ?: 'Warehouse User';

            /*
            |--------------------------------------------------------------------------
            | 4. Prepare issue type and comment
            |--------------------------------------------------------------------------
            */
            $issueType = strtolower(
                trim($request->input('issue_type', 'damaged'))
            );

            $comment = trim($request->input('comment'));

            /*
            |--------------------------------------------------------------------------
            | 5. Collect uploaded images
            |--------------------------------------------------------------------------
            */
            $uploadedImages = [];

            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');

                if (is_array($photo)) {
                    foreach ($photo as $file) {
                        if ($file) {
                            $uploadedImages[] = $file;
                        }
                    }
                } else {
                    $uploadedImages[] = $photo;
                }
            }

            if ($request->hasFile('photos')) {
                foreach ((array) $request->file('photos') as $file) {
                    if ($file) {
                        $uploadedImages[] = $file;
                    }
                }
            }

            if (count($uploadedImages) > 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can upload a maximum of 10 images.',
                ], 422);
            }

            $uploadedImageUrls = [];

            foreach ($uploadedImages as $file) {
                if (!$file->isValid()) {
                    throw new \RuntimeException(
                        'One or more image uploads failed.'
                    );
                }

                $path = $file->store('discrepancies', 'public');

                if (!$path) {
                    throw new \RuntimeException(
                        'Unable to store discrepancy image.'
                    );
                }

                $storedFiles[] = $path;

                $uploadedImageUrls[] = asset(
                    \Illuminate\Support\Facades\Storage::disk('public')
                        ->url($path)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Collect existing photo URLs
            |--------------------------------------------------------------------------
            */
            $existingImageUrls = [];

            $extractUrls = function ($value) use (
                &$extractUrls,
                &$existingImageUrls
            ) {
                if (is_string($value)) {
                    $url = trim($value);

                    if (
                        $url !== '' &&
                        filter_var($url, FILTER_VALIDATE_URL) &&
                        in_array(
                            strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                            ['http', 'https'],
                            true
                        )
                    ) {
                        $existingImageUrls[] = $url;
                    }

                    return;
                }

                if (is_array($value)) {
                    if (isset($value['url'])) {
                        $extractUrls($value['url']);
                        return;
                    }

                    foreach ($value as $item) {
                        $extractUrls($item);
                    }
                }
            };

            $extractUrls($request->input('photo_url'));

            $allImageUrls = array_values(array_unique(array_merge(
                $uploadedImageUrls,
                $existingImageUrls
            )));

            /*
            |--------------------------------------------------------------------------
            | 7. Save discrepancy, images, item flag, and order status
            |--------------------------------------------------------------------------
            */
            $result = \Illuminate\Support\Facades\DB::transaction(
                function () use (
                    $order,
                    $orderItem,
                    $userId,
                    $userName,
                    $issueType,
                    $comment,
                    $allImageUrls
                ) {
                    // Capture old statuses before updating.
                    $oldOrderStatus = $order->status;
                    $oldItemStatus = $orderItem->status;

                    // Create discrepancy record.
                    $discrepancy = OrderItemDiscrepancy::create([
                        'order_id'      => $order->id,
                        'order_item_id' => $orderItem->id,
                        'user_id'       => $userId,
                        'user_name'     => $userName,
                        'issue_type'    => $issueType,
                        'comment'       => $comment,
                        'status'        => 'open',
                        'photo_url'     => $allImageUrls[0] ?? null,
                    ]);

                    // Save one database record per image.
                    foreach ($allImageUrls as $imageUrl) {
                        FlagImage::create([
                            'order_id'       => $order->id,
                            'line_item_id'   => $orderItem->line_item_id,
                            'order_item_id'  => $orderItem->id,
                            'discrepancy_id' => $discrepancy->id,
                            'image_url'      => $imageUrl,
                            'photo_url'      => $imageUrl,
                            'issue_type'     => $issueType,
                            'comment'        => $comment,
                            'user_id'        => $userId,
                            'user_name'      => $userName,
                        ]);
                    }

                    // Flag the order item.
                    $orderItem->update([
                        'is_flagged' => true,
                        'flag_reason' => $issueType,
                    ]);

                    // Update the main order status in the orders table.
                    $order->update([
                        'status' => 'flagged',
                    ]);

                    // Log the main order status change.
                    OrderStatusLog::create([
                        'order_id'      => $order->id,
                        'order_item_id' => $orderItem->id,
                        'user_id'       => $userId,
                        'user_name'     => $userName,
                        'action'        => 'item_discrepancy_flagged',
                        'old_status'    => $oldOrderStatus,
                        'new_status'    => 'flagged',
                        'notes'         => "Flagged discrepancy ({$issueType}): {$comment}",
                    ]);

                    return [
                        'discrepancy'    => $discrepancy,
                        'images'         => $allImageUrls,
                        'old_order_status' => $oldOrderStatus,
                        'new_order_status' => 'flagged',
                        'old_item_status'  => $oldItemStatus,
                    ];
                }
            );

            $discrepancy = $result['discrepancy'];

            /*
            |--------------------------------------------------------------------------
            | 8. Return response
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'success' => true,
                'message' => 'Order item discrepancy flagged successfully. Order status updated to flagged.',
                'data' => [
                    'id'                => $discrepancy->id,
                    'order_number'      => $order->order_number,
                    'order_id'          => $order->id,
                    'order_status'      => $result['new_order_status'],
                    'previous_order_status' => $result['old_order_status'],
                    'order_item_id'     => $orderItem->id,
                    'line_item_id'      => $orderItem->line_item_id,
                    'product_code'      => $orderItem->product_code,
                    'product_name'      => $orderItem->product_name,
                    'barcode'           => $orderItem->barcode,
                    'is_flagged'        => (bool) $orderItem->is_flagged,
                    'user_id'           => $discrepancy->user_id,
                    'user_name'         => $discrepancy->user_name,
                    'issue_type'        => $discrepancy->issue_type,
                    'comment'           => $discrepancy->comment,
                    'status'            => $discrepancy->status,
                    'photo_url'         => $discrepancy->photo_url,
                    'images'             => $result['images'],
                    'image_count'        => count($result['images']),
                    'created_at'         => $discrepancy->created_at
                        ? $discrepancy->created_at->toIso8601String()
                        : null,
                ],
            ], 200);

        } catch (\Throwable $e) {
            // Clean up newly uploaded files if database saving fails.
            foreach ($storedFiles as $path) {
                try {
                    \Illuminate\Support\Facades\Storage::disk('public')
                        ->delete($path);
                } catch (\Throwable $cleanupException) {
                    \Log::warning('Failed to clean up discrepancy image', [
                        'path'    => $path,
                        'message' => $cleanupException->getMessage(),
                    ]);
                }
            }

            \Log::error('Error flagging order item discrepancy', [
                'message'       => $e->getMessage(),
                'order_number'  => $request->input('order_number'),
                'order_item_id' => $request->input('order_item_id'),
                'line_item_id'  => $request->input('line_item_id'),
                'barcode'       => $request->input('barcode'),
                'product_code'  => $request->input('product_code'),
                'trace'         => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flag order item discrepancy.',
                'error'   => config('app.debug')
                    ? $e->getMessage()
                    : 'An internal server error occurred.',
            ], 500);
        }
    }



    /**
     * Get list of reported order item discrepancies
     * Route: GET /api/orders/items/discrepancies
     */
    public function getDiscrepancies(Request $request)
    {
        $query = OrderItemDiscrepancy::with(['order', 'orderItem', 'user']);

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->input('order_id'));
        }

        if ($request->filled('order_item_id')) {
            $query->where('order_item_id', $request->input('order_item_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('issue_type')) {
            $query->where('issue_type', $request->input('issue_type'));
        }

        $discrepancies = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $discrepancies->map(function ($d) {
                return [
                    'id' => $d->id,
                    'order_id' => $d->order_id,
                    'order_number' => $d->order ? $d->order->order_number : null,
                    'order_item_id' => $d->order_item_id,
                    'line_item_id' => $d->orderItem ? $d->orderItem->line_item_id : null,
                    'product_code' => $d->orderItem ? $d->orderItem->product_code : null,
                    'product_name' => $d->orderItem ? $d->orderItem->product_name : null,
                    'user_id' => $d->user_id,
                    'user_name' => $d->user_name ?? ($d->user ? $d->user->name : null),
                    'issue_type' => $d->issue_type,
                    'comment' => $d->comment,
                    'status' => $d->status,
                    'photo_url' => $d->photo_url,
                    'created_at' => $d->created_at ? $d->created_at->toIso8601String() : null,
                ];
            }),
        ]);
    }

    /**
     * Resolve or update status of an item discrepancy
     * Route: POST /api/orders/items/resolve-discrepancy
     */
    public function resolveDiscrepancy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'discrepancy_id' => 'required',
            'status' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $discrepancy = OrderItemDiscrepancy::find($request->input('discrepancy_id'));

        if (!$discrepancy) {
            return response()->json([
                'success' => false,
                'message' => 'Discrepancy record not found.',
            ], 404);
        }

        $newStatus = strtolower(trim($request->input('status')));
        $discrepancy->update([
            'status' => $newStatus,
        ]);

        if (in_array($newStatus, ['resolved', 'rejected'])) {
            $hasOpen = OrderItemDiscrepancy::where('order_item_id', $discrepancy->order_item_id)
                ->where('status', 'open')
                ->exists();
            if (!$hasOpen && $discrepancy->orderItem) {
                $discrepancy->orderItem->update(['is_flagged' => false, 'flag_reason' => null]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Discrepancy status updated successfully.',
            'data' => $discrepancy,
        ]);
    }
}
