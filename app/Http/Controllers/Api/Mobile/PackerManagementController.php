<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPackerAssigned;
use App\Models\OrderStatusLog;
use App\Models\PackerVerification;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PackerManagementController extends Controller
{
        protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Get list of picked orders ready for packer.
     * Criteria: Order status is 'picked' and all items in order items table are picked.
     * Route: GET /api/orders/packer/ready-to-pack
     * Route: GET /api/orders/packer/picked/{user_id?}
     * Route: GET /api/orders-picked/{id?}
     */
    public function getPickedOrdersForPacker(Request $request, $userId = null)
    {
        $packerId = $userId ? trim((string) $userId) : null;
        if (!$packerId) {
            $packerId = $request->query('user_id') ?? $request->query('packer_id') ?? $request->query('id');
            if ($packerId) {
                $packerId = trim((string) $packerId);
            }
        }

        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        $query = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'packerAssignment',
            'logs.user'
        ]);

        // Condition 1: Order overall status is 'picked'
        $query->where('status', 'picked');

        // Condition 2: Ensure all items in order_items table are picked
        $query->whereDoesntHave('items', function ($iq) {
            $iq->where('status', 'pending');
        });

        // Filter by assigned packer ID if passed; otherwise default to unassigned ready-to-pack orders
        if (!empty($packerId)) {
            $query->where(function ($q) use ($packerId) {
                $q->where('packed_by', $packerId)
                  ->orWhere('packed_user_name', $packerId)
                  ->orWhereHas('packerAssignment', function ($pa) use ($packerId) {
                      $pa->where('packer_assigned_user_id', $packerId)
                        ->orWhere('packer_assigned_user_name', $packerId);
                  });
            });
        } else {
            // Default: Exclude any order that is already assigned to a packer in orders table or orders_packer_assigned table
            $query->whereNull('packed_by')
                  ->whereDoesntHave('packerAssignment');
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('assigned_user_name', 'like', "%{$search}%")
                  ->orWhere('packed_user_name', 'like', "%{$search}%");
            });
        }

        $orders = $query->orderBy('updated_at', 'desc')
                        ->orderBy('created_at', 'desc')
                        ->get();

        // Format orders for mobile API
        $formattedOrders = $orders->map(function ($order) {
            // Additional check: Ensure order has items and no item is unpicked
            $hasUnpicked = $order->items->contains(fn($item) => $item->status === 'pending' || is_null($item->picked_by));
            if ($hasUnpicked) {
                return null;
            }
            return $this->formatOrderDetails($order, 'picked');
        })
        ->filter()
        ->values();

        return response()->json([
            'success' => true,
            'message' => 'Picked orders ready for packing retrieved successfully.',
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Get packed status orders for packer.
     * Route: GET /api/orders/packer/packed/{user_id?}
     * Route: GET /api/orders-packed/{id?}
     */
    public function getPackedOrders(Request $request, $userId = null)
    {
        $idStr = $userId ? trim((string) $userId) : null;
        if (!$idStr) {
            $idStr = $request->query('user_id') ?? $request->query('packer_id') ?? $request->query('id');
            if ($idStr) {
                $idStr = trim((string) $idStr);
            }
        }

        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        $query = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'logs.user'
        ]);

        if (!empty($idStr)) {
            // Filter orders packed by specific user ID/name or containing items packed by specific user ID
            $query->where(function ($q) use ($idStr) {
                $q->where('packed_by', $idStr)
                  ->orWhere('packed_user_name', $idStr)
                  ->orWhereHas('items', function ($sub) use ($idStr) {
                      $sub->where('packed_by', $idStr)
                          ->orWhere('packed_user_name', $idStr);
                  });
            });
        } else {
            // Return all orders that have status 'packed' or have items packed
            $query->where(function ($q) {
                $q->where('status', 'packed')
                  ->orWhereHas('items', function ($sub) {
                      $sub->where('status', 'packed');
                  });
            });
        }

        $orders = $query->orderBy('updated_at', 'desc')
                        ->orderBy('created_at', 'desc')
                        ->get();

        $formattedOrders = $orders->map(function ($order) {
            return $this->formatOrderDetails($order, 'packed');
        })
        ->filter(function ($order) {
            return !empty($order['items']) || $order['status'] === 'packed';
        })
        ->values();

        return response()->json([
            'success' => true,
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Get active/assigned picked orders for a specific packer user ID (status = 'picked')
     * Route: GET /api/orders/packer/{id}
     */
    public function apiPackerOrdersById(Request $request, $id)
    {
        $idStr = trim((string) $id);

        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        $orders = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'packerAssignment',
            'logs.user',
        ])
        ->where('status', 'picked')
        ->where(function ($q) use ($idStr) {
            $q->where('packed_by', $idStr)
              ->orWhere('packed_user_name', $idStr)
              ->orWhereHas('packerAssignment', function ($pa) use ($idStr) {
                  $pa->where('packer_assigned_user_id', $idStr)
                    ->orWhere('packer_assigned_user_name', $idStr);
              });
        })
        ->orderBy('updated_at', 'desc')
        ->get();

        $formattedOrders = $orders->map(fn($order) => $this->formatOrderDetails($order, 'picked'))
            ->filter(fn($ord) => count($ord['items']) > 0 || $ord['status'] === 'picked')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Get completed packed orders for a specific packer user ID (status = 'packed' or 'delivered')
     * Route: GET /api/orders/packed-completed/{id?}
     * Route: GET /api/orders/packer/complete/{id?}
     */
    public function apiPackerOrdersComplete(Request $request, $id = null)
    {
        $idStr = $id ? trim((string) $id) : null;
        if (!$idStr) {
            $idStr = $request->query('user_id') ?? $request->query('packer_id') ?? $request->query('id');
            if ($idStr) {
                $idStr = trim((string) $idStr);
            }
        }

        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        $query = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'packerAssignment',
            'logs.user'
        ])
        ->whereIn('status', ['packed', 'delivered']);

        if (!empty($idStr)) {
            $query->where(function ($q) use ($idStr) {
                $q->where('packed_by', $idStr)
                  ->orWhere('packed_user_name', $idStr)
                  ->orWhereHas('packerAssignment', function ($pa) use ($idStr) {
                      $pa->where('packer_assigned_user_id', $idStr)
                        ->orWhere('packer_assigned_user_name', $idStr);
                  })
                  ->orWhereHas('items', function ($iq) use ($idStr) {
                      $iq->where('packed_by', $idStr)
                        ->orWhere('packer_verified_by', $idStr);
                  });
            });
        }

        $orders = $query->orderBy('packed_at', 'desc')
                        ->orderBy('updated_at', 'desc')
                        ->get();

        $formattedOrders = $orders->map(fn($order) => $this->formatOrderDetails($order, 'packed'))
            ->filter(fn($ord) => count($ord['items']) > 0 || $ord['status'] === 'packed')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Assign a packer user to pack an order.
     * Rule: Order overall status must be 'picked' and ALL items in order must be picked.
     * Route: POST /api/orders/packer/assign-me
     * Route: POST /api/orders/packer/assign
     */
    public function assignPacker(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'user_id' => 'nullable',
            'packer_assigned_user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'packer_assigned_user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIdStr = (string) $request->input('order_id');

        // Find Order
        $order = Order::with(['items', 'packerAssignment'])
            ->Where('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        // Rule Check: Order status must be 'picked' AND all items must be picked
        $unpickedItems = $order->items->filter(function ($item) {
            return $item->status !== 'picked' && !in_array($item->status, ['packed', 'delivered']) && is_null($item->picked_by);
        });

        if ($order->status !== 'picked' || $unpickedItems->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot assign packer. Order status must be 'picked' and all items in the order must be picked before a packer can assign themselves.",
                'order_status' => $order->status,
                'unpicked_items_count' => $unpickedItems->count(),
                'total_items_count' => $order->items->count(),
                'unpicked_items' => $unpickedItems->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'status' => $item->status,
                    ];
                })->values(),
            ], 400);
        }

        // Determine user (packer)
        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('packer_assigned_user_id') ?? $request->input('user_id'));
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('packer_assigned_user_name') ?? $request->input('user_name') ?? 'Packer User'));

        return DB::transaction(function () use ($order, $validUserId, $userName, $request) {
            $assignment = OrderPackerAssigned::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'packer_assigned_user_id' => $validUserId,
                    'packer_assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]
            );

            // Update order packed_by details
            $order->update([
                'packed_by' => $validUserId,
                'packed_user_name' => $userName,
                'packed_at' => now(),
            ]);

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'packer_assigned_to_order',
                'old_status' => $order->status,
                'new_status' => $order->status,
                'notes' => $request->input('notes', "Packer {$userName} assigned to pack order {$order->order_number}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Packer '{$userName}' successfully assigned to pack order {$order->order_number}.",
                'data' => [
                    'order' => $order->fresh(['items', 'packerAssignment']),
                    'packer_assignment' => $assignment->fresh(),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Unassign a packer from an order.
     * Route: POST /api/orders/packer/unassign-me
     * Route: POST /api/orders/packer/unassign
     */
    public function unassignPacker(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'user_id' => 'nullable',
            'packer_assigned_user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIdStr = (string) $request->input('order_id');

        $order = Order::with(['items', 'packerAssignment'])
            ->where('id', $orderIdStr)
            ->orWhere('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        $assignment = OrderPackerAssigned::where('order_id', $order->id)->first();

        if (!$assignment && is_null($order->packed_by)) {
            return response()->json([
                'success' => false,
                'message' => "Order {$order->order_number} is not assigned to any packer.",
            ], 400);
        }

        // Cannot unassign if packing is already completed
        if (in_array($order->status, ['packed', 'delivered'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot unassign packer. Order {$order->order_number} has already been completed/packed.",
            ], 400);
        }

        return DB::transaction(function () use ($order, $assignment, $request) {
            $user = Auth::user();
            $userId = $user ? $user->id : ($request->input('packer_assigned_user_id') ?? $request->input('user_id'));
            $dbUser = $userId ? \App\Models\User::find($userId) : null;
            $validUserId = $dbUser ? $dbUser->id : null;
            $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('packer_assigned_user_name') ?? $request->input('user_name') ?? 'System User'));

            if ($assignment) {
                $assignment->delete();
            }

            $order->update([
                'packed_by' => null,
                'packed_user_name' => null,
                'packed_at' => null,
            ]);

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'packer_unassigned_from_order',
                'old_status' => $order->status,
                'new_status' => $order->status,
                'notes' => $request->input('notes', "Unassigned packer from order {$order->order_number}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Packer successfully unassigned from order {$order->order_number}.",
                'data' => [
                    'order' => $order->fresh(['items', 'packerAssignment']),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Packer scans item barcode to verify item before packing
     * Route: POST /api/orders/packer/verify-item
     */
    public function verifyItemBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'scanned_barcode' => 'required_without_all:barcode|nullable|string',
            'barcode' => 'nullable|string',
            'order_item_id' => 'nullable',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIdStr = (string) $request->input('order_id');
        $scannedBarcode = trim((string) ($request->input('scanned_barcode') ?? $request->input('barcode')));
        $orderItemId = $request->input('order_item_id');

        // Find Order
        $order = Order::with('items')
            ->where('id', $orderIdStr)
            ->orWhere('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
                'is_verified' => false,
            ], 404);
        }

        // Determine packer user
        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Packer User'));

        // Match item inside this order
        $itemQuery = OrderItem::where('order_id', $order->id);

        if (!empty($orderItemId)) {
            $itemQuery->where('id', $orderItemId);
        } else {
            $itemQuery->where(function ($q) use ($scannedBarcode) {
                $q->where('barcode', $scannedBarcode)
                  ->orWhere('line_item_id', $scannedBarcode)
                  ->orWhere('product_name', 'like', "%{$scannedBarcode}%");
            });
        }

        $orderItem = $itemQuery->first();

        if (!$orderItem) {
            return response()->json([
                'success' => false,
                'message' => "Scanned barcode '{$scannedBarcode}' does not match any item in Order {$order->order_number}.",
                'is_verified' => false,
            ], 400);
        }

        // Check if item has been picked
        $isPicked = in_array($orderItem->status, ['picked', 'packed', 'delivered']) || !is_null($orderItem->picked_by);

        if (!$isPicked) {
            return response()->json([
                'success' => false,
                'message' => "Item '{$orderItem->product_name}' is not yet picked.",
                'is_verified' => false,
                'status' => $orderItem->status,
            ], 400);
        }

        // Record verification
        $orderItem->update([
            'is_packer_verified' => true,
            'packer_verified_by' => $validUserId,
            'packer_verified_user_name' => $userName,
            'packer_verified_at' => now(),
        ]);

        $verificationLog = PackerVerification::create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'packer_id' => $validUserId,
            'packer_name' => $userName,
            'scanned_barcode' => $scannedBarcode,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        $auditLog = OrderStatusLog::create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'user_id' => $validUserId,
            'user_name' => $userName,
            'action' => 'packer_barcode_verified',
            'old_status' => $orderItem->status,
            'new_status' => $orderItem->status,
            'notes' => "Packer {$userName} verified barcode {$scannedBarcode} for item {$orderItem->product_name}",
        ]);

        $order->refresh();
        $totalItems = $order->items->count();
        $verifiedItemsCount = $order->items->where('is_packer_verified', true)->count();
        $remainingCount = $totalItems - $verifiedItemsCount;

        return response()->json([
            'success' => true,
            'message' => "Item '{$orderItem->product_name}' successfully verified by packer {$userName}.",
            'data' => [
                'is_verified' => true,
                'verified_at' => $orderItem->packer_verified_at ? $orderItem->packer_verified_at->toIso8601String() : null,
                'packer' => [
                    'id' => $validUserId,
                    'name' => $userName,
                ],
                'order_item' => $orderItem->fresh(),
                'verification_record' => $verificationLog,
                'log' => $auditLog,
                'packing_summary' => [
                    'total_items' => $totalItems,
                    'verified_items' => $verifiedItemsCount,
                    'remaining_unverified_items' => $remainingCount,
                    'all_items_verified' => ($verifiedItemsCount === $totalItems),
                ],
            ],
        ]);
    }

    /**
     * Packer completes packing and enters bag count
     * Route: POST /api/orders/packer/complete-packing
     */
    public function completePacking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'bag_count' => 'required|integer|min:1',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIdStr = (string) $request->input('order_id');
        $bagCount = (int) $request->input('bag_count');

        $order = Order::with('items')
            ->where('id', $orderIdStr)
            ->orWhere('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        // Verify all items in order are packer verified before completing packing
        $unverifiedItems = $order->items->filter(function ($item) {
            return !$item->is_packer_verified;
        });

        if ($unverifiedItems->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete packing. Please verify all items before completing packing.',
                'unverified_items_count' => $unverifiedItems->count(),
                'total_items_count' => $order->items->count(),
                'unverified_items' => $unverifiedItems->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'barcode' => $item->barcode,
                        'is_packer_verified' => false,
                    ];
                })->values(),
            ], 400);
        }

        // Determine user (packer)
        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Packer User'));

        return DB::transaction(function () use ($order, $bagCount, $validUserId, $userName, $request) {
            $oldOrderStatus = $order->status;

            // Update item statuses to packed
            foreach ($order->items as $item) {
                $item->update([
                    'status' => 'packed',
                    'packed_by' => $validUserId,
                    'packed_user_name' => $userName,
                    'packed_at' => now(),
                ]);
            }

            // Update parent order status to ready_to_assign
            $order->update([
                'status' => 'ready_to_assign',
                'bag_count' => $bagCount,
                'packed_by' => $validUserId,
                'packed_user_name' => $userName,
                'packed_at' => now(),
            ]);

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'order_packed',
                'old_status' => $oldOrderStatus,
                'new_status' => 'ready_to_assign',
                'notes' => $request->input('notes', "Order packed into {$bagCount} bag(s) by packer {$userName} and ready to assign to driver"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} successfully packed into {$bagCount} bag(s).",
                'data' => [
                    'order' => $order->fresh(['items', 'packerVerifications']),
                    'bag_count' => $bagCount,
                    'packed_by' => [
                        'id' => $validUserId,
                        'name' => $userName,
                        'packed_at' => $order->packed_at ? $order->packed_at->toIso8601String() : null,
                    ],
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Helper method to format single order details consistently for mobile frontend
     *
     * @param Order $order
     * @param string $itemFilter 'unpicked', 'picked', 'packed', or 'all'
     * @return array
     */
    private function formatOrderDetails(Order $order, string $itemFilter = 'all')
    {
        $allItems = $order->items;

        $totalItems = $allItems->count();
        $pickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count();
        $packedItems = $allItems->whereIn('status', ['packed', 'delivered'])->count();
        $deliveredItems = $allItems->where('status', 'delivered')->count();

        // Filter line items based on context
        if ($itemFilter === 'unpicked') {
            $filteredItems = $allItems->filter(function ($item) {
                return is_null($item->picked_by) && !in_array($item->status, ['picked', 'packed', 'delivered']);
            });
        } elseif ($itemFilter === 'picked') {
            $filteredItems = $allItems->filter(function ($item) {
                return !is_null($item->picked_by) || in_array($item->status, ['picked', 'packed', 'delivered']);
            });
        } elseif ($itemFilter === 'packed') {
            $filteredItems = $allItems->filter(function ($item) {
                return !is_null($item->packed_by) || in_array($item->status, ['packed', 'delivered']);
            });
        } else {
            $filteredItems = $allItems;
        }

        $pickers = $allItems->map(function ($item) {
            if ($item->picked_by || $item->picked_user_name) {
                return [
                    'id' => $item->picked_by ? (int) $item->picked_by : null,
                    'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                    'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        $packers = $allItems->map(function ($item) {
            if ($item->packed_by || $item->packed_user_name) {
                return [
                    'id' => $item->packed_by ? (int) $item->packed_by : null,
                    'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                    'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'bag_count' => (int) ($order->bag_count ?? 0),
            'assigned_user' => $order->assigned_to ? [
                'id' => (int) $order->assigned_to,
                'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
                'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
            ] : null,
            'packed_user' => ($order->packed_by || $order->packed_user_name || $order->packerAssignment) ? [
                'id' => $order->packerAssignment ? (int) $order->packerAssignment->packer_assigned_user_id : ($order->packed_by ? (int) $order->packed_by : null),
                'name' => $order->packerAssignment ? $order->packerAssignment->packer_assigned_user_name : ($order->packedUser ? $order->packedUser->name : ($order->packed_user_name ?? 'Packer User')),
                'assigned_at' => ($order->packerAssignment && $order->packerAssignment->assigned_at) ? $order->packerAssignment->assigned_at->toIso8601String() : null,
                'packed_at' => $order->packed_at ? $order->packed_at->toIso8601String() : null,
            ] : null,
            'packer_assignment' => $order->packerAssignment ? [
                'id' => $order->packerAssignment->id,
                'order_id' => $order->packerAssignment->order_id,
                'packer_assigned_user_id' => $order->packerAssignment->packer_assigned_user_id ? (int) $order->packerAssignment->packer_assigned_user_id : null,
                'packer_assigned_user_name' => $order->packerAssignment->packer_assigned_user_name,
                'assigned_at' => $order->packerAssignment->assigned_at ? $order->packerAssignment->assigned_at->toIso8601String() : null,
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
                'unpicked_items' => $totalItems - $pickedItems,
            ],
            'items' => $filteredItems->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'line_item_id' => $item->line_item_id,
                    'product_name' => $item->product_name,
                    'image' => $item->image,
                    'image_url' => $item->image,
                    'product_image' => $item->image,
                    'product_image_url' => $item->image,
                    'barcode' => $item->barcode,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'status' => $item->status,
                    'is_packer_verified' => (bool) $item->is_packer_verified,
                    'packer_verified_by' => $item->packer_verified_by
                        ? [
                            'id' => (int) $item->packer_verified_by,
                            'name' => $item->packerVerifiedUser
                                ? $item->packerVerifiedUser->name
                                : ($item->packer_verified_user_name ?? 'Packer User'),
                            'verified_at' => $item->packer_verified_at
                                ? $item->packer_verified_at->toIso8601String()
                                : null,
                        ]
                        : null,
                    'is_flagged' => (bool) $item->is_flagged,
                    'flag_reason' => $item->flag_reason,
                    'assigned_user' => $item->assigned_to
                        ? [
                            'id' => (int) $item->assigned_to,
                            'name' => $item->assignedUser
                                ? $item->assignedUser->name
                                : ($item->assigned_user_name ?? 'Picker User'),
                            'assigned_at' => $item->assigned_at
                                ? $item->assigned_at->toIso8601String()
                                : null,
                        ]
                        : null,
                    'picked_user' => ($item->picked_by || $item->picked_user_name)
                        ? [
                            'id' => $item->picked_by ? (int) $item->picked_by : null,
                            'name' => $item->pickedUser
                                ? $item->pickedUser->name
                                : ($item->picked_user_name ?? 'Picker User'),
                            'picked_at' => $item->picked_at
                                ? $item->picked_at->toIso8601String()
                                : null,
                        ]
                        : null,
                    'packed_user' => ($item->packed_by || $item->packed_user_name)
                        ? [
                            'id' => $item->packed_by ? (int) $item->packed_by : null,
                            'name' => $item->packedUser
                                ? $item->packedUser->name
                                : ($item->packed_user_name ?? 'Packer User'),
                            'packed_at' => $item->packed_at
                                ? $item->packed_at->toIso8601String()
                                : null,
                        ]
                        : null,
                    'delivered_user' => ($item->delivered_by || $item->delivered_user_name)
                        ? [
                            'id' => $item->delivered_by ? (int) $item->delivered_by : null,
                            'name' => $item->deliveredUser
                                ? $item->deliveredUser->name
                                : ($item->delivered_user_name ?? 'Driver User'),
                            'delivered_at' => $item->delivered_at
                                ? $item->delivered_at->toIso8601String()
                                : null,
                        ]
                        : null,
                    'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                    'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                    'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                ];
            })->values(),
            'logs' => $order->logs,
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }

    /**
     * Parse items input into clean item IDs and line item IDs array
     */
    protected function extractItemIdentifiers($itemsInput): array
    {
        $itemIds = [];
        $lineItemIds = [];

        if (is_array($itemsInput)) {
            foreach ($itemsInput as $val) {
                if (is_array($val) || is_object($val)) {
                    $valArr = (array) $val;
                    if (isset($valArr['id']) || isset($valArr['order_item_id'])) {
                        $itemIds[] = $valArr['id'] ?? $valArr['order_item_id'];
                    }
                    if (isset($valArr['line_item_id'])) {
                        $lineItemIds[] = (string) $valArr['line_item_id'];
                    }
                } elseif (is_numeric($val)) {
                    $itemIds[] = (int) $val;
                } elseif (is_string($val) && !empty($val)) {
                    if (ctype_digit($val)) {
                        $itemIds[] = (int) $val;
                    } else {
                        $lineItemIds[] = $val;
                    }
                }
            }
        }

        return [
            'item_ids' => array_values(array_unique($itemIds)),
            'line_item_ids' => array_values(array_unique($lineItemIds)),
        ];
    }

    /**
     * Assign order(s) to packer with order items specified in an array.
     * Updates packed_by (user ID), packed_user_name, and packed_at timestamp.
     * Route: POST /api/orders/assign-packer-items
     * Route: POST /api/orders/packer/assign-items
     * Route: POST /api/orders/assign-packer-with-items
     */
    public function assignPackerWithItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'nullable|array',
            'order_id' => 'required_without:orders',
            'order_items' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'items' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'packer_id' => 'nullable',
            'user_id' => 'nullable',
            'packed_by' => 'nullable',
            'packer_assigned_user_id' => 'nullable',
            'packer_name' => 'nullable|string',
            'user_name' => 'nullable|string',
            'packer_assigned_user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Determine packer user details globally or fallback
        $authUser = Auth::user();
        $globalUserId = $authUser ? $authUser->id : ($request->input('packer_id') ?? $request->input('user_id') ?? $request->input('packed_by') ?? $request->input('packer_assigned_user_id'));

        if ($globalUserId && is_numeric($globalUserId)) {
            $globalUserId = (int) $globalUserId;
        }

        $dbUser = $globalUserId ? \App\Models\User::find($globalUserId) : null;
        $globalUserName = $authUser ? $authUser->name : ($dbUser ? $dbUser->name : ($request->input('packer_name') ?? $request->input('user_name') ?? $request->input('packer_assigned_user_name')));

        if (!$globalUserId && $globalUserName) {
            $foundUser = \App\Models\User::where('name', 'like', "%{$globalUserName}%")->first();
            if ($foundUser) {
                $globalUserId = $foundUser->id;
                $globalUserName = $foundUser->name;
            }
        }

        if (!$globalUserId && !$globalUserName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Please pass packer_id, user_id, packed_by, or user_name.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $authUser, $globalUserId, $globalUserName) {
            $ordersPayload = [];
            if ($request->filled('orders') && is_array($request->input('orders'))) {
                $ordersPayload = $request->input('orders');
            } else {
                $itemsInput = $request->input('order_items')
                    ?? $request->input('order_item_ids')
                    ?? $request->input('items')
                    ?? $request->input('line_item_ids')
                    ?? [];

                $ordersPayload[] = [
                    'order_id' => $request->input('order_id'),
                    'order_items' => $itemsInput,
                    'packer_id' => $request->input('packer_id') ?? $request->input('user_id') ?? $request->input('packed_by') ?? $request->input('packer_assigned_user_id'),
                    'packer_name' => $request->input('packer_name') ?? $request->input('user_name') ?? $request->input('packer_assigned_user_name'),
                ];
            }

            $processedOrders = [];
            $totalAssignedItemsCount = 0;

            foreach ($ordersPayload as $orderEntry) {
                $orderIdRaw = trim((string) ($orderEntry['order_id'] ?? $orderEntry['id'] ?? ''));
                if (empty($orderIdRaw)) {
                    continue;
                }

                // Resolve per-entry packer user ID & name
                $entryUserId = $orderEntry['packer_id'] ?? $orderEntry['user_id'] ?? $orderEntry['packed_by'] ?? $orderEntry['packer_assigned_user_id'] ?? $globalUserId;
                if ($entryUserId && is_numeric($entryUserId)) {
                    $entryUserId = (int) $entryUserId;
                }

                $entryDbUser = $entryUserId ? \App\Models\User::find($entryUserId) : null;
                $entryUserName = $orderEntry['packer_name']
                    ?? $orderEntry['user_name']
                    ?? $orderEntry['packer_assigned_user_name']
                    ?? ($entryDbUser ? $entryDbUser->name : $globalUserName);

                if (!$entryUserId && $entryUserName) {
                    $foundUser = \App\Models\User::where('name', 'like', "%{$entryUserName}%")->first();
                    if ($foundUser) {
                        $entryUserId = $foundUser->id;
                        $entryUserName = $foundUser->name;
                    }
                }

                if (!$entryUserName) {
                    $entryUserName = 'Packer User';
                }

                $cleanOrderId = ltrim($orderIdRaw, '#');
                $order = Order::with('items')
                    ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                        $q->where('id', $orderIdRaw)
                          ->orWhere('order_number', $orderIdRaw)
                          ->orWhere('order_number', $cleanOrderId)
                          ->orWhere('order_number', '#' . $cleanOrderId);
                    })
                    ->first();

                if (!$order) {
                    try {
                        $this->shopifyService->syncOrdersToDatabase();
                        $order = Order::with('items')
                            ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                                $q->where('id', $orderIdRaw)
                                  ->orWhere('order_number', $orderIdRaw)
                                  ->orWhere('order_number', $cleanOrderId)
                                  ->orWhere('order_number', '#' . $cleanOrderId);
                            })
                            ->first();
                    } catch (\Exception $e) {
                        // Continue if offline
                    }
                }

                if (!$order) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => "Order '{$orderIdRaw}' not found in system.",
                        ], 404);
                    }
                    continue;
                }

                // Extract item identifiers
                $itemsInput = $orderEntry['order_items']
                    ?? $orderEntry['order_item_ids']
                    ?? $orderEntry['items']
                    ?? $orderEntry['line_item_ids']
                    ?? [];

                $parsedIds = $this->extractItemIdentifiers($itemsInput);
                $itemIds = $parsedIds['item_ids'];
                $lineItemIds = $parsedIds['line_item_ids'];

                $itemsQuery = OrderItem::where('order_id', $order->id);

                if (!empty($itemIds) || !empty($lineItemIds)) {
                    $itemsQuery->where(function ($q) use ($itemIds, $lineItemIds) {
                        if (!empty($itemIds)) {
                            $q->orWhereIn('id', $itemIds);
                        }
                        if (!empty($lineItemIds)) {
                            $q->orWhereIn('line_item_id', $lineItemIds);
                        }
                    });
                }

                $items = $itemsQuery->get();

                if ($items->isEmpty()) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No matching order items found for this order.',
                        ], 404);
                    }
                    continue;
                }

                $assignedItems = collect();
                $oldOrderStatus = $order->status;
                $nowTimestamp = now();

                foreach ($items as $item) {
                    $oldItemStatus = $item->status;

                    $item->update([
                        'packed_by' => $entryUserId,
                        'packed_user_name' => $entryUserName,
                        'packed_at' => $nowTimestamp,
                    ]);

                    $assignedItems->push($item->fresh());

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $entryUserId,
                        'user_name' => $entryUserName,
                        'action' => 'item_assigned_to_packer',
                        'old_status' => $oldItemStatus,
                        'new_status' => $oldItemStatus,
                        'notes' => $request->input('notes', "Assigned item {$item->product_name} to packer {$entryUserName}"),
                    ]);
                }

                // Update order packed_by, packed_user_name, packed_at
                $order->update([
                    'packed_by' => $entryUserId,
                    'packed_user_name' => $entryUserName,
                    'packed_at' => $nowTimestamp,
                ]);

                // Also update OrderPackerAssigned table for compatibility
                OrderPackerAssigned::updateOrCreate(
                    ['order_id' => $order->id],
                    [
                        'packer_assigned_user_id' => $entryUserId,
                        'packer_assigned_user_name' => $entryUserName,
                        'assigned_at' => $nowTimestamp,
                    ]
                );

                if (in_array($order->status, ['picked', 'picking'])) {
                    $order->update(['status' => 'packing']);
                }

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $entryUserId,
                    'user_name' => $entryUserName,
                    'action' => 'packer_assigned_to_order',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input('notes', "Assigned order {$order->order_number} to packer {$entryUserName} with {$assignedItems->count()} item(s)"),
                ]);

                $totalAssignedItemsCount += $assignedItems->count();

                $processedOrders[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'packed_by' => $order->packed_by,
                    'packed_user_name' => $order->packed_user_name,
                    'packed_at' => $order->packed_at ? $order->packed_at->toIso8601String() : null,
                    'assigned_items_count' => $assignedItems->count(),
                    'assigned_items' => $assignedItems,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "Order(s) successfully assigned to packer with order items.",
                'data' => [
                    'packer' => [
                        'id' => $globalUserId,
                        'name' => $globalUserName,
                    ],
                    'assigned_orders_count' => count($processedOrders),
                    'total_assigned_items_count' => $totalAssignedItemsCount,
                    'orders' => $processedOrders,
                ],
            ]);
        });
    }

    /**
     * Unassign an order with order items (in array) which is assigned to packer.
     * Route: POST /api/orders/unassign-packer-items
     * Route: POST /api/orders/packer/unassign-items
     * Route: POST /api/orders/unassign-packer-with-items
     */
    public function unassignPackerWithItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'nullable|array',
            'order_id' => 'required_without:orders',
            'order_items' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'items' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'packer_id' => 'nullable',
            'user_id' => 'nullable',
            'packed_by' => 'nullable',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $authUser = Auth::user();
        $userId = $authUser ? $authUser->id : ($request->input('packer_id') ?? $request->input('user_id') ?? $request->input('packed_by'));
        if ($userId && is_numeric($userId)) {
            $userId = (int) $userId;
        }

        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $authUser ? $authUser->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

        return DB::transaction(function () use ($request, $userId, $userName) {
            $ordersPayload = [];
            if ($request->filled('orders') && is_array($request->input('orders'))) {
                $ordersPayload = $request->input('orders');
            } else {
                $itemsInput = $request->input('order_items')
                    ?? $request->input('order_item_ids')
                    ?? $request->input('items')
                    ?? $request->input('line_item_ids')
                    ?? [];

                $ordersPayload[] = [
                    'order_id' => $request->input('order_id'),
                    'order_items' => $itemsInput,
                ];
            }

            $processedOrders = [];
            $totalUnassignedItemsCount = 0;

            foreach ($ordersPayload as $orderEntry) {
                $orderIdRaw = trim((string) ($orderEntry['order_id'] ?? $orderEntry['id'] ?? ''));
                if (empty($orderIdRaw)) {
                    continue;
                }

                $cleanOrderId = ltrim($orderIdRaw, '#');
                $order = Order::with('items')
                    ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                        $q->where('id', $orderIdRaw)
                          ->orWhere('order_number', $orderIdRaw)
                          ->orWhere('order_number', $cleanOrderId)
                          ->orWhere('order_number', '#' . $cleanOrderId);
                    })
                    ->first();

                if (!$order) {
                    try {
                        $this->shopifyService->syncOrdersToDatabase();
                        $order = Order::with('items')
                            ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                                $q->where('id', $orderIdRaw)
                                  ->orWhere('order_number', $orderIdRaw)
                                  ->orWhere('order_number', $cleanOrderId)
                                  ->orWhere('order_number', '#' . $cleanOrderId);
                            })
                            ->first();
                    } catch (\Exception $e) {
                        // Ignore sync exception if offline
                    }
                }

                if (!$order) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => "Order '{$orderIdRaw}' not found in system.",
                        ], 404);
                    }
                    continue;
                }

                $itemsInput = $orderEntry['order_items']
                    ?? $orderEntry['order_item_ids']
                    ?? $orderEntry['items']
                    ?? $orderEntry['line_item_ids']
                    ?? [];

                $parsedIds = $this->extractItemIdentifiers($itemsInput);
                $itemIds = $parsedIds['item_ids'];
                $lineItemIds = $parsedIds['line_item_ids'];

                $itemsQuery = OrderItem::where('order_id', $order->id);

                if (!empty($itemIds) || !empty($lineItemIds)) {
                    $itemsQuery->where(function ($q) use ($itemIds, $lineItemIds) {
                        if (!empty($itemIds)) {
                            $q->orWhereIn('id', $itemIds);
                        }
                        if (!empty($lineItemIds)) {
                            $q->orWhereIn('line_item_id', $lineItemIds);
                        }
                    });
                }

                $items = $itemsQuery->get();

                if ($items->isEmpty()) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No matching order items found for this order.',
                        ], 404);
                    }
                    continue;
                }

                $unassignedItems = collect();
                $skippedItems = collect();

                foreach ($items as $item) {
                    $isDelivered = in_array($item->status, ['delivered']) || !is_null($item->delivered_by);

                    if ($isDelivered) {
                        $skippedItems->push([
                            'item_id' => $item->id,
                            'line_item_id' => $item->line_item_id,
                            'product_name' => $item->product_name,
                            'status' => $item->status,
                            'reason' => 'Item has already been delivered and cannot be unassigned from packer.',
                        ]);
                    } else {
                        $oldItemStatus = $item->status;
                        $item->update([
                            'packed_by' => null,
                            'packed_user_name' => null,
                            'packed_at' => null,
                            'status' => ($item->status === 'packing') ? 'picked' : $item->status,
                        ]);

                        $unassignedItems->push($item->fresh());

                        OrderStatusLog::create([
                            'order_id' => $order->id,
                            'order_item_id' => $item->id,
                            'user_id' => $userId,
                            'user_name' => $userName,
                            'action' => 'item_unassigned_from_packer',
                            'old_status' => $oldItemStatus,
                            'new_status' => $item->status,
                            'notes' => $request->input('notes', "Unassigned item {$item->product_name} from packer"),
                        ]);
                    }
                }

                // Evaluate order assignment status after items unassignment
                $allItems = OrderItem::where('order_id', $order->id)->get();
                $hasPackedItems = $allItems->whereNotNull('packed_by')->count() > 0 || $allItems->whereNotNull('packed_user_name')->count() > 0;
                $hasCompletedPacking = $allItems->whereIn('status', ['packed', 'delivered'])->count() > 0;

                $oldOrderStatus = $order->status;
                if (!$hasPackedItems && !$hasCompletedPacking) {
                    $order->update([
                        'packed_by' => null,
                        'packed_user_name' => null,
                        'packed_at' => null,
                        'status' => ($order->status === 'packing') ? 'picked' : $order->status,
                    ]);

                    OrderPackerAssigned::where('order_id', $order->id)->delete();

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'action' => 'packer_unassigned_from_order',
                        'old_status' => $oldOrderStatus,
                        'new_status' => $order->status,
                        'notes' => $request->input('notes', "Unassigned packer from order {$order->order_number} as all items are unassigned"),
                    ]);
                } elseif (!$hasPackedItems) {
                    $order->update([
                        'packed_by' => null,
                        'packed_user_name' => null,
                        'packed_at' => null,
                    ]);

                    OrderPackerAssigned::where('order_id', $order->id)->delete();
                }

                $totalUnassignedItemsCount += $unassignedItems->count();

                $processedOrders[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'packed_by' => $order->packed_by,
                    'packed_user_name' => $order->packed_user_name,
                    'unassigned_items_count' => $unassignedItems->count(),
                    'unassigned_items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "Order items successfully unassigned from packer ({$totalUnassignedItemsCount} item(s) unassigned across " . count($processedOrders) . " order(s)).",
                'data' => [
                    'unassigned_orders_count' => count($processedOrders),
                    'total_unassigned_items_count' => $totalUnassignedItemsCount,
                    'orders' => $processedOrders,
                ],
            ]);
        });
    }
}
