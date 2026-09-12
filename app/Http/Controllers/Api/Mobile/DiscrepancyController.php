<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class DiscrepancyController extends Controller
{
        public function flagItemDiscrepancy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'barcode' => 'nullable',
            'product_code' => 'nullable',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'issue_type' => 'nullable|string',
            'comment' => 'required|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
            'photo_url' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {

            // =========================================================
            // 1. Locate the OrderItem
            // =========================================================

            $itemQuery = OrderItem::query();

            if ($request->filled('order_item_id')) {

                $itemQuery->where(
                    'id',
                    $request->input('order_item_id')
                );

            } elseif ($request->filled('line_item_id')) {

                $itemQuery->where(
                    'line_item_id',
                    $request->input('line_item_id')
                );

            } elseif ($request->filled('barcode')) {

                $itemQuery->where(
                    'barcode',
                    $request->input('barcode')
                );

            } elseif ($request->filled('product_code')) {

                $itemQuery->where(
                    'product_code',
                    $request->input('product_code')
                );
            }

            // If order_id is provided, also filter by order_id
            if ($request->filled('order_id')) {
                $itemQuery->where(
                    'order_id',
                    $request->input('order_id')
                );
            }

            $orderItem = $itemQuery->first();

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order item not found with provided identifiers.',
                ], 404);
            }


            // =========================================================
            // 2. Handle Photo
            // =========================================================

            $photoUrl = null;

            /*
            * If an actual image file is uploaded,
            * store it and generate the public URL.
            *
            * This takes priority over photo_url.
            */
            if ($request->hasFile('photo')) {

                $path = $request->file('photo')->store(
                    'discrepancies',
                    'public'
                );

                $photoUrl = asset('storage/' . $path);

            } else {

                /*
                * photo_url is optional.
                *
                * Sometimes frontend applications may send:
                *
                * "photo_url": "https://example.com/image.jpg"
                *
                * or accidentally send an array/object.
                *
                * We only save it when it is actually a string.
                */
                $incomingPhotoUrl = $request->input('photo_url');

                if (is_string($incomingPhotoUrl)) {

                    $incomingPhotoUrl = trim($incomingPhotoUrl);

                    if ($incomingPhotoUrl !== '') {
                        $photoUrl = $incomingPhotoUrl;
                    }

                } elseif (is_array($incomingPhotoUrl)) {

                    /*
                    * If frontend sends:
                    *
                    * photo_url: {
                    *     url: "https://..."
                    * }
                    *
                    * or:
                    *
                    * photo_url: ["https://..."]
                    *
                    * try to extract the URL safely.
                    */

                    if (
                        isset($incomingPhotoUrl['url']) &&
                        is_string($incomingPhotoUrl['url'])
                    ) {
                        $photoUrl = trim($incomingPhotoUrl['url']);

                    } elseif (
                        isset($incomingPhotoUrl[0]) &&
                        is_string($incomingPhotoUrl[0])
                    ) {
                        $photoUrl = trim($incomingPhotoUrl[0]);
                    }
                }
            }


            // =========================================================
            // 3. Resolve User Details
            // =========================================================

            $userId = $request->input('user_id');

            if (!$userId) {
                $userId = Auth::id();
            }

            $userName = $request->input('user_name');

            if (!$userName && $userId) {

                $userObj = \App\Models\User::find($userId);

                $userName = $userObj
                    ? $userObj->name
                    : 'Warehouse User';
            }

            if (!$userName) {
                $userName = 'Warehouse User';
            }


            // =========================================================
            // 4. Issue Type & Comment
            // =========================================================

            $issueType = strtolower(
                trim(
                    $request->input('issue_type', 'damaged')
                )
            );

            $comment = trim(
                $request->input('comment')
            );


            // =========================================================
            // 5. Create OrderItemDiscrepancy
            // =========================================================

            $discrepancy = OrderItemDiscrepancy::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'issue_type' => $issueType,
                'comment' => $comment,
                'status' => 'open',
                'photo_url' => $photoUrl,
            ]);


            // =========================================================
            // 6. Mark Order Item as Flagged
            // =========================================================

            $orderItem->update([
                'is_flagged' => true,
                'flag_reason' => $issueType,
            ]);


            // =========================================================
            // 7. Log Status Action
            // =========================================================

            OrderStatusLog::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => 'item_discrepancy_flagged',
                'old_status' => $orderItem->status,
                'new_status' => $orderItem->status,
                'notes' => "Flagged discrepancy ({$issueType}): {$comment}",
            ]);


            // =========================================================
            // 8. Return Response
            // =========================================================

            return response()->json([
                'success' => true,
                'message' => 'Order item discrepancy flagged successfully.',

                'data' => [
                    'id' => $discrepancy->id,

                    'order_id' => $discrepancy->order_id,

                    'order_item_id' => $discrepancy->order_item_id,

                    'line_item_id' => $orderItem->line_item_id,

                    'product_code' => $orderItem->product_code,

                    'product_name' => $orderItem->product_name,

                    'barcode' => $orderItem->barcode,

                    'user_id' => $discrepancy->user_id,

                    'user_name' => $discrepancy->user_name,

                    'issue_type' => $discrepancy->issue_type,

                    'comment' => $discrepancy->comment,

                    'status' => $discrepancy->status,

                    'photo_url' => $discrepancy->photo_url,

                    'created_at' => $discrepancy->created_at
                        ? $discrepancy->created_at->toIso8601String()
                        : null,
                ],
            ], 200);

        } catch (\Throwable $e) {

            \Log::error(
                'Error flagging order item discrepancy',
                [
                    'message' => $e->getMessage(),
                    'order_id' => $request->input('order_id'),
                    'order_item_id' => $request->input('order_item_id'),
                    'line_item_id' => $request->input('line_item_id'),
                    'barcode' => $request->input('barcode'),
                    'product_code' => $request->input('product_code'),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Failed to flag order item discrepancy.',
                'error' => $e->getMessage(),
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
