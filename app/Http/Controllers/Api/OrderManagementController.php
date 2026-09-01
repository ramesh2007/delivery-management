<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderPackerAssigned;
use App\Models\OrderDriverAssigned;
use App\Models\OrderStatusLog;
use App\Models\PackerVerification;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderManagementController extends Controller
{
    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Get list of all orders with customer details and line items for mobile app.
     * Auto-syncs with Shopify silently so mobile users don't need a manual sync endpoint.
     */
    // public function index(Request $request)
    // {
    //     // Auto-sync orders from Shopify if requested or by default
    //     if ($request->boolean('auto_sync', true)) {
    //         try {
    //             $this->shopifyService->syncOrdersToDatabase();
    //         } catch (\Exception $e) {
    //             // Continue using local DB if sync fails
    //         }
    //     }

    //     $query = Order::with([
    //         'items.assignedUser',
    //         'items.pickedUser',
    //         'items.packedUser',
    //         'items.deliveredUser',
    //         'assignedUser',
    //         'pickedUser',
    //         'packedUser',
    //         'deliveredUser'
    //     ]);

    //     // Optional status filter
    //     if ($request->has('status') && !empty($request->query('status'))) {
    //         $query->where('status', $request->query('status'));
    //     }

    //     // Optional assigned picker filter
    //     if ($request->has('assigned_to') && !empty($request->query('assigned_to'))) {
    //         $query->where('assigned_to', $request->query('assigned_to'));
    //     } elseif ($request->boolean('unassigned')) {
    //         $query->whereNull('assigned_to');
    //     }

    //     if ($request->has('search')) {
    //         $search = $request->query('search');
    //         $query->where(function ($q) use ($search) {
    //             $q->where('order_number', 'like', "%{$search}%")
    //               ->orWhere('customer_name', 'like', "%{$search}%")
    //               ->orWhere('customer_phone', 'like', "%{$search}%")
    //               ->orWhere('assigned_user_name', 'like', "%{$search}%");
    //         });
    //     }

    //     $orders = $query->orderBy('created_at', 'desc')->get();

    //     // Format response structure optimized for mobile list view
    //     $formattedOrders = $orders->map(function ($order) {
    //         $totalItems = $order->items->count();
    //         $pickedItems = $order->items->where('status', 'picked')->count();
    //         $packedItems = $order->items->where('status', 'packed')->count();
    //         $deliveredItems = $order->items->where('status', 'delivered')->count();

    //         // Collect unique pickers and packers involved across all line items
    //         $pickers = $order->items->map(function ($item) {
    //             if ($item->picked_by || $item->picked_user_name) {
    //                 return [
    //                     'id' => $item->picked_by ? (int) $item->picked_by : null,
    //                     'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
    //                     'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
    //                 ];
    //             }
    //             return null;
    //         })->filter()->unique('name')->values();

    //         $packers = $order->items->map(function ($item) {
    //             if ($item->packed_by || $item->packed_user_name) {
    //                 return [
    //                     'id' => $item->packed_by ? (int) $item->packed_by : null,
    //                     'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
    //                     'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
    //                 ];
    //             }
    //             return null;
    //         })->filter()->unique('name')->values();

    //         return [
    //             'order_id' => $order->id,
    //             'order_number' => $order->order_number,
    //             'status' => $order->status,
    //             'bag_count' => (int) ($order->bag_count ?? 0),
    //             'assigned_user' => $order->assigned_to ? [
    //                 'id' => (int) $order->assigned_to,
    //                 'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
    //                 'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
    //             ] : null,
    //             'driver_user' => ($order->delivered_by || $order->delivered_user_name) ? [
    //                 'id' => $order->delivered_by ? (int) $order->delivered_by : null,
    //                 'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
    //                 'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
    //             ] : null,
    //             'pickers' => $pickers,
    //             'packers' => $packers,
    //             'customer' => [
    //                 'name' => $order->customer_name ?? 'N/A',
    //                 'phone' => $order->customer_phone ?? 'N/A',
    //                 'delivery_address' => $order->delivery_address ?? 'N/A',
    //             ],
    //             'summary' => [
    //                 'total_amount' => (float) $order->total_amount,
    //                 'total_items' => $totalItems,
    //                 'picked_items' => $pickedItems,
    //                 'packed_items' => $packedItems,
    //                 'delivered_items' => $deliveredItems,
    //             ],
    //             'items' => $order->items->map(function ($item) {
    //                 return [
    //                     'item_id' => $item->id,
    //                     'line_item_id' => $item->line_item_id,
    //                     'product_id' => $item->product_id,
    //                     'product_code' => $item->product_code,
    //                     'barcode' => $item->barcode,
    //                     'product_name' => $item->product_name,
    //                     'quantity' => $item->quantity,
    //                     'unit_price' => (float) $item->unit_price,
    //                     'status' => $item->status,
    //                     'is_packer_verified' => (bool) $item->is_packer_verified,
    //                     'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
    //                         'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
    //                         'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
    //                         'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
    //                     ] : null,
    //                     'assigned_user' => $item->assigned_to ? [
    //                         'id' => (int) $item->assigned_to,
    //                         'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
    //                         'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
    //                     ] : null,
    //                     'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
    //                         'id' => $item->picked_by ? (int) $item->picked_by : null,
    //                         'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
    //                         'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
    //                     ] : null,
    //                     'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
    //                         'id' => $item->packed_by ? (int) $item->packed_by : null,
    //                         'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
    //                         'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
    //                     ] : null,
    //                     'delivered_user' => ($item->delivered_by || $item->delivered_user_name) ? [
    //                         'id' => $item->delivered_by ? (int) $item->delivered_by : null,
    //                         'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
    //                         'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
    //                     ] : null,
    //                     'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
    //                     'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
    //                     'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
    //                 ];
    //             }),
    //             'created_at' => $order->created_at->toIso8601String(),
    //         ];
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Orders retrieved successfully.',
    //         'count' => $formattedOrders->count(),
    //         'data' => $formattedOrders,
    //     ]);
    // }

    /**
     * Get a single order detail by ID or order_number.
     * Route: GET /api/orders/{id}
     */
    public function show($id)
    {
        $idStr = (string) $id;

        $order = Order::with(['items', 'logs', 'assignedUser', 'deliveredUser', 'items.packerVerifiedUser'])
            ->where('id', $idStr)
            ->orWhere('order_number', $idStr)
            ->orWhere('order_number', ltrim($idStr, '#'))
            ->orWhere('order_number', '#' . ltrim($idStr, '#'))
            ->first();

        if (!$order) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
                $order = Order::with(['items', 'logs', 'assignedUser', 'deliveredUser', 'items.packerVerifiedUser'])
                    ->where('id', $idStr)
                    ->orWhere('order_number', $idStr)
                    ->orWhere('order_number', ltrim($idStr, '#'))
                    ->orWhere('order_number', '#' . ltrim($idStr, '#'))
                    ->first();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $totalItems = $order->items->count();
        $pickedItems = $order->items->where('status', 'picked')->count();
        $packedItems = $order->items->where('status', 'packed')->count();
        $deliveredItems = $order->items->where('status', 'delivered')->count();

        // Collect unique pickers and packers involved across all line items
        $pickers = $order->items->map(function ($item) {
            if ($item->picked_by || $item->picked_user_name) {
                return [
                    'id' => $item->picked_by ? (int) $item->picked_by : null,
                    'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                    'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        $packers = $order->items->map(function ($item) {
            if ($item->packed_by || $item->packed_user_name) {
                return [
                    'id' => $item->packed_by ? (int) $item->packed_by : null,
                    'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                    'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'bag_count' => (int) ($order->bag_count ?? 0),
                'assigned_user' => $order->assigned_to ? [
                    'id' => (int) $order->assigned_to,
                    'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
                    'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
                ] : null,
                'driver_user' => ($order->delivered_by || $order->delivered_user_name) ? [
                    'id' => $order->delivered_by ? (int) $order->delivered_by : null,
                    'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
                    'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
                ] : null,
                'pickers' => $pickers,
                'packers' => $packers,
                'customer' => [
                    'name' => $order->customer_name ?? 'N/A',
                    'phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                ],
                'summary' => [
                    'total_amount' => (float) $order->total_amount,
                    'total_items' => $totalItems,
                    'picked_items' => $pickedItems,
                    'packed_items' => $packedItems,
                    'delivered_items' => $deliveredItems,
                ],
                'items' => $order->items->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'image' => $item->image,
                        'image_url' => $item->image,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => $item->status,
                        'is_packer_verified' => (bool) $item->is_packer_verified,
                        'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
                            'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
                            'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
                            'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                        ] : null,
                        'assigned_user' => $item->assigned_to ? [
                            'id' => (int) $item->assigned_to,
                            'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
                            'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
                        ] : null,
                        'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
                            'id' => $item->picked_by ? (int) $item->picked_by : null,
                            'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                            'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                        ] : null,
                        'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
                            'id' => $item->packed_by ? (int) $item->packed_by : null,
                            'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                            'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                        ] : null,
                        'delivered_user' => ($item->delivered_by || $item->delivered_user_name) ? [
                            'id' => $item->delivered_by ? (int) $item->delivered_by : null,
                            'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
                            'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                        ] : null,
                        'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                        'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                        'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                    ];
                }),
                'logs' => $order->logs,
                'created_at' => $order->created_at->toIso8601String(),
            ],
        ]);
    }






    /**
     * Sync orders from Shopify into database (orders & order_items) with 'pending' status
     */
    public function syncShopify(Request $request)
    {
        $shop = $request->input('shop');
        $accessToken = $request->input('access_token');

        $result = $this->shopifyService->syncOrdersToDatabase($shop, $accessToken);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }











    /**
     * Update overall order status with user log
     */
    public function updateOrderStatus(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,picking,picked,packing,packed,ready_to_assign,out_for_delivery,in_delivery,delivered,cancelled',
            'notes' => 'nullable|string',
            'user_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::find($orderId);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $oldStatus = $order->status;
        $newStatus = $request->input('status');

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

        if ($newStatus === 'out_for_delivery' || $newStatus === 'delivered') {
            $order->delivered_by = $validUserId;
            $order->delivered_user_name = $userName;
            $order->delivered_at = now();
        }

        $order->status = $newStatus;
        $order->save();

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $userName = $user ? $user->name : ($request->input('user_name') ?? 'System User');

        $log = OrderStatusLog::create([
            'order_id' => $order->id,
            'user_id' => $validUserId,
            'user_name' => $userName,
            'action' => 'order_status_updated',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $request->input('notes', "Order status updated from '{$oldStatus}' to '{$newStatus}'"),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Order status updated successfully.",
            'data' => [
                'order' => $order->fresh(['items']),
                'log' => $log,
            ],
        ]);
    }

    /**
     * Get user audit history logs for an order
     */
    public function getLogs($orderId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $logs = OrderStatusLog::where('order_id', $order->id)
            ->with(['orderItem', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'logs' => $logs,
        ]);
    }





    /**
     * Flag an order item with a discrepancy (damaged, missing, wrong item, expired, etc.)
     * Route: POST /api/orders/items/flag-discrepancy
     */
    // public function flagItemDiscrepancy(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'order_id' => 'nullable',
    //         'order_item_id' => 'nullable',
    //         'line_item_id' => 'nullable',
    //         'barcode' => 'nullable',
    //         'product_code' => 'nullable',
    //         'user_id' => 'nullable',
    //         'user_name' => 'nullable',
    //         'issue_type' => 'nullable|string',
    //         'comment' => 'required|string',
    //         'photo' => 'nullable|image|max:10240',
    //         'photo_url' => 'nullable|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation failed',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     // 1. Locate the OrderItem
    //     $itemQuery = OrderItem::query();

    //     if ($request->filled('order_item_id')) {
    //         $itemQuery->where('id', $request->input('order_item_id'));
    //     } elseif ($request->filled('line_item_id')) {
    //         $itemQuery->where('line_item_id', $request->input('line_item_id'));
    //     } elseif ($request->filled('barcode')) {
    //         $itemQuery->where('barcode', $request->input('barcode'));
    //     } elseif ($request->filled('product_code')) {
    //         $itemQuery->where('product_code', $request->input('product_code'));
    //     }

    //     if ($request->filled('order_id')) {
    //         $itemQuery->where('order_id', $request->input('order_id'));
    //     }

    //     $orderItem = $itemQuery->first();

    //     if (!$orderItem) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Order item not found with provided identifiers.',
    //         ], 404);
    //     }

    //     // 2. Handle optional photo upload
    //     $photoUrl = $request->input('photo_url');
    //     if ($request->hasFile('photo')) {
    //         $path = $request->file('photo')->store('discrepancies', 'public');
    //         $photoUrl = asset('storage/' . $path);
    //     }

    //     // 3. Resolve user details
    //     $userId = $request->input('user_id') ?? Auth::id();
    //     $userName = $request->input('user_name');
    //     if (!$userName && $userId) {
    //         $userObj = \App\Models\User::find($userId);
    //         $userName = $userObj ? $userObj->name : 'Warehouse User';
    //     }
    //     if (!$userName) {
    //         $userName = 'Warehouse User';
    //     }

    //     $issueType = strtolower(trim($request->input('issue_type', 'damaged')));
    //     $comment = trim($request->input('comment'));

    //     // 4. Create OrderItemDiscrepancy record
    //     $discrepancy = OrderItemDiscrepancy::create([
    //         'order_id' => $orderItem->order_id,
    //         'order_item_id' => $orderItem->id,
    //         'user_id' => $userId,
    //         'user_name' => $userName,
    //         'issue_type' => $issueType,
    //         'comment' => $comment,
    //         'status' => 'open',
    //         'photo_url' => $photoUrl,
    //     ]);

    //     // 5. Mark item as flagged
    //     $orderItem->update([
    //         'is_flagged' => true,
    //         'flag_reason' => $issueType,
    //     ]);

    //     // 6. Log status action
    //     OrderStatusLog::create([
    //         'order_id' => $orderItem->order_id,
    //         'order_item_id' => $orderItem->id,
    //         'user_id' => $userId,
    //         'user_name' => $userName,
    //         'action' => 'item_discrepancy_flagged',
    //         'old_status' => $orderItem->status,
    //         'new_status' => $orderItem->status,
    //         'notes' => "Flagged discrepancy ({$issueType}): {$comment}",
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Order item discrepancy flagged successfully.',
    //         'data' => [
    //             'id' => $discrepancy->id,
    //             'order_id' => $discrepancy->order_id,
    //             'order_item_id' => $discrepancy->order_item_id,
    //             'line_item_id' => $orderItem->line_item_id,
    //             'product_name' => $orderItem->product_name,
    //             'user_id' => $discrepancy->user_id,
    //             'user_name' => $discrepancy->user_name,
    //             'issue_type' => $discrepancy->issue_type,
    //             'comment' => $discrepancy->comment,
    //             'status' => $discrepancy->status,
    //             'photo_url' => $discrepancy->photo_url,
    //             'created_at' => $discrepancy->created_at ? $discrepancy->created_at->toIso8601String() : null,
    //         ],
    //     ]);
    // }
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

    /**
     * Update payment status and payment method for an order number.
     * Route: POST /api/orders/update-payment-status
     * Route: POST /api/orders/payment-status
     * Route: POST /api/resource/sales-order/update-payment-status
     * Route: POST /api/resource/Sales Order/update-payment-status
     */
    public function updatePaymentStatus(Request $request)
    {
        $rawJson = json_decode($request->getContent(), true) ?? [];

        $orderNumberInput = $request->input('order_number')
            ?? ($rawJson['order_number'] ?? null)
            ?? $request->input('ordernumber')
            ?? ($rawJson['ordernumber'] ?? null)
            ?? $request->input('order_id')
            ?? ($rawJson['order_id'] ?? null)
            ?? $request->input('order')
            ?? ($rawJson['order'] ?? null);

        $paymentMethod = $request->input('payment_method')
            ?? ($rawJson['payment_method'] ?? null)
            ?? $request->input('payment_type')
            ?? ($rawJson['payment_type'] ?? null)
            ?? 'cash';

        $paymentStatus = $request->input('payment_status')
            ?? ($rawJson['payment_status'] ?? null)
            ?? $request->input('status')
            ?? ($rawJson['status'] ?? null)
            ?? 'paid';

        $amountInput = $request->input('collected_amount')
            ?? ($rawJson['collected_amount'] ?? null)
            ?? $request->input('amount')
            ?? ($rawJson['amount'] ?? null)
            ?? $request->input('total_amount')
            ?? ($rawJson['total_amount'] ?? null);

        if (empty($orderNumberInput)) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'The order_number parameter is required.',
            ], 422);
        }

        $numOrIdStr = trim((string) $orderNumberInput);
        $cleanNum = ltrim($numOrIdStr, '#');

        $order = Order::where('order_number', $numOrIdStr)
            ->orWhere('order_number', $cleanNum)
            ->orWhere('order_number', '#' . $cleanNum)
            ->orWhere('order_number', 'SO-' . $cleanNum)
            ->orWhere('id', $numOrIdStr)
            ->first();

        if (!$order) {
            $order = Order::create([
                'order_number' => $numOrIdStr,
                'customer_name' => 'Guest Customer',
                'total_amount' => !is_null($amountInput) ? (float) $amountInput : 0.00,
                'status' => 'Pending',
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => !is_null($amountInput) ? (float) $amountInput : 0.00,
            ]);
        } else {
            $collectedAmount = !is_null($amountInput) ? (float) $amountInput : (float) ($order->collected_amount ?: $order->total_amount);
            $order->update([
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => $collectedAmount,
            ]);
        }

        // Also update OrderDriverAssigned if assignment exists
        $assignment = OrderDriverAssigned::where('order_id', $order->id)
            ->orWhere('order_number', $order->order_number)
            ->first();

        if ($assignment) {
            $assignment->update([
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => $order->collected_amount,
            ]);
        }

        // Log audit history
        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('user_id') ?? ($rawJson['user_id'] ?? null));
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

        OrderStatusLog::create([
            'order_id' => $order->id,
            'user_id' => $user ? $user->id : ($dbUser ? $dbUser->id : null),
            'user_name' => $userName,
            'action' => 'payment_status_updated',
            'old_status' => $order->status,
            'new_status' => $order->status,
            'notes' => "Payment status updated to '{$paymentStatus}' (Method: {$paymentMethod}, Amount: {$order->collected_amount})",
        ]);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => "Payment status updated successfully for order '{$order->order_number}'.",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'collected_amount' => (float) $order->collected_amount,
                'total_amount' => (float) $order->total_amount,
                'order_status' => $order->status,
                'order' => $order->fresh(['items']),
            ],
        ], 200);
    }

    /**
     * Create Return or Replacement request for order items.
     * Route: POST /api/orders/return-replacement
     * Route: POST /api/orders/create-return-replacement
     */
    public function createReturnReplacement(Request $request)
    {
        $rawJson = json_decode($request->getContent(), true) ?? [];

        $orderNumberInput = $request->input('order_number')
            ?? ($rawJson['order_number'] ?? null)
            ?? $request->input('ordernumber')
            ?? ($rawJson['ordernumber'] ?? null)
            ?? $request->input('order_id')
            ?? ($rawJson['order_id'] ?? null);

        if (empty($orderNumberInput)) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'The order_number parameter is required.',
            ], 422);
        }

        $actionTypeInput = $request->input('action_type')
            ?? ($rawJson['action_type'] ?? null)
            ?? $request->input('type')
            ?? ($rawJson['type'] ?? null)
            ?? $request->input('action')
            ?? ($rawJson['action'] ?? null)
            ?? 'refund';

        $actionType = strtolower(trim((string) $actionTypeInput));
        if (in_array($actionType, ['return', 'refund'])) {
            $actionType = 'refund';
        } elseif (in_array($actionType, ['replace', 'replacement', 'exchange'])) {
            $actionType = 'replace';
        } else {
            $actionType = 'refund';
        }

        $reason = $request->input('reason')
            ?? ($rawJson['reason'] ?? null)
            ?? 'Other';

        $notes = $request->input('notes')
            ?? ($rawJson['notes'] ?? null)
            ?? $request->input('comment')
            ?? ($rawJson['comment'] ?? null);

        // Raw items input: item_id, item_ids, order_item_id, order_item_ids, items
        $itemInput = $request->input('item_ids')
            ?? ($rawJson['item_ids'] ?? null)
            ?? $request->input('item_id')
            ?? ($rawJson['item_id'] ?? null)
            ?? $request->input('order_item_ids')
            ?? ($rawJson['order_item_ids'] ?? null)
            ?? $request->input('order_item_id')
            ?? ($rawJson['order_item_id'] ?? null)
            ?? $request->input('items')
            ?? ($rawJson['items'] ?? null);

        $itemIds = [];
        if (is_array($itemInput)) {
            foreach ($itemInput as $val) {
                if (is_array($val) && isset($val['item_id'])) {
                    $itemIds[] = $val['item_id'];
                } elseif (is_scalar($val)) {
                    $itemIds[] = $val;
                }
            }
        } elseif (!is_null($itemInput)) {
            $itemIds[] = $itemInput;
        }

        // Find Order
        $numOrIdStr = trim((string) $orderNumberInput);
        $cleanNum = ltrim($numOrIdStr, '#');

        $order = Order::with('items')->where('order_number', $numOrIdStr)
            ->orWhere('order_number', $cleanNum)
            ->orWhere('order_number', '#' . $cleanNum)
            ->orWhere('order_number', 'SO-' . $cleanNum)
            ->orWhere('id', $numOrIdStr)
            ->first();

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => "Order '{$numOrIdStr}' not found.",
            ], 404);
        }

        // Get matching items
        $orderItems = collect();
        if (!empty($itemIds)) {
            $orderItems = OrderItem::where('order_id', $order->id)
                ->where(function ($q) use ($itemIds) {
                    $q->whereIn('id', $itemIds)
                      ->orWhereIn('line_item_id', $itemIds)
                      ->orWhereIn('product_code', $itemIds);
                })->get();
        }

        if ($orderItems->isEmpty()) {
            $orderItems = $order->items;
        }

        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('user_id') ?? ($rawJson['user_id'] ?? null));
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Admin User'));

        $affectedItems = [];

        foreach ($orderItems as $item) {
            // Flag item
            $item->update([
                'is_flagged' => true,
                'flag_reason' => "{$actionType}: {$reason}",
            ]);

            // Create discrepancy record
            $discrepancy = OrderItemDiscrepancy::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'issue_type' => $actionType,
                'comment' => "Reason: {$reason}" . (!empty($notes) ? " | Notes: {$notes}" : ""),
                'status' => 'open',
            ]);

            $affectedItems[] = [
                'item_id' => $item->id,
                'line_item_id' => $item->line_item_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'quantity' => $item->quantity,
                'action_type' => $actionType,
                'reason' => $reason,
                'discrepancy_id' => $discrepancy->id,
            ];
        }

        // Update Order status
        $newOrderStatus = ($actionType === 'refund') ? 'refund' : 'exchange';
        $order->update([
            'status' => $newOrderStatus,
        ]);

        // Sync driver assignment if exists
        $assignment = OrderDriverAssigned::where('order_id', $order->id)
            ->orWhere('order_number', $order->order_number)
            ->first();

        if ($assignment) {
            $assignment->update([
                'order_status' => $newOrderStatus,
                'driver_status' => $actionType === 'refund' ? 'refund' : 'exchange',
            ]);
        }

        // Log audit entry
        OrderStatusLog::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'user_name' => $userName,
            'action' => 'return_replacement_created',
            'old_status' => $order->status,
            'new_status' => $newOrderStatus,
            'notes' => "Created {$actionType} request. Reason: {$reason}" . (!empty($notes) ? " ({$notes})" : ""),
        ]);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => ucfirst($actionType) . ' request created successfully.',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'action_type' => $actionType,
                'reason' => $reason,
                'notes' => $notes,
                'order_status' => $newOrderStatus,
                'items_count' => count($affectedItems),
                'items' => $affectedItems,
            ],
        ], 200);
    }
}
