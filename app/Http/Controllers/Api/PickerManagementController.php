<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PickerManagementController extends Controller
{
    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Get list of all orders with customer details and line items for mobile app.
     * Auto-syncs with Shopify silently so mobile users don't need a manual sync endpoint.
     * Route: GET /api/orders
     */
    public function index(Request $request)
    {
        // Auto-sync orders from Shopify if requested or by default
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Continue using local DB if sync fails
            }
        }

        $query = Order::with([
            'items' => function ($q) {
                $q->where('status', 'pending')
                  ->whereNull('assigned_to');
            },

            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',

            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
        ]);

        // Optional order status filter
        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('assigned_to') && !empty($request->query('assigned_to'))) {

            $assignedTo = $request->query('assigned_to');

            $query->whereHas('items', function ($q) use ($assignedTo) {
                $q->where('assigned_to', $assignedTo)
                  ->where('status', 'pending');
            });

            $query->with([
                'items' => function ($q) use ($assignedTo) {
                    $q->where('assigned_to', $assignedTo)
                      ->where('status', 'pending');
                },
                'items.assignedUser',
                'items.pickedUser',
                'items.packedUser',
                'items.deliveredUser',
                'items.packerVerifiedUser',
            ]);

        } elseif ($request->boolean('unassigned')) {

            $query->whereHas('items', function ($q) {
                $q->whereNull('assigned_to')
                  ->where('status', 'pending');
            });

        } else {

            $query->whereHas('items', function ($q) {
                $q->whereNull('assigned_to')
                  ->where('status', 'pending');
            });
        }

        // Search
        if ($request->has('search')) {

            $search = $request->query('search');

            $query->where(function ($q) use ($search) {

                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('assigned_user_name', 'like', "%{$search}%");

            });
        }

        $orders = $query
            ->orderBy('created_at', 'desc')
            ->get();

        // Format response
        $formattedOrders = $orders
            ->map(function ($order) {

                $order->setRelation(
                    'items',
                    $order->items
                        ->filter(function ($item) {

                            return $item->status === 'pending'
                                && is_null($item->assigned_to);

                        })
                        ->values()
                );

                // If no matching items, don't return the order
                if ($order->items->isEmpty()) {
                    return null;
                }

                $totalItems = $order->items->count();

                $pickedItems = $order->items
                    ->where('status', 'picked')
                    ->count();

                $packedItems = $order->items
                    ->where('status', 'packed')
                    ->count();

                $deliveredItems = $order->items
                    ->where('status', 'delivered')
                    ->count();

                $pickers = $order->items
                    ->map(function ($item) {

                        if ($item->picked_by || $item->picked_user_name) {

                            return [
                                'id' => $item->picked_by
                                    ? (int) $item->picked_by
                                    : null,

                                'name' => $item->pickedUser
                                    ? $item->pickedUser->name
                                    : ($item->picked_user_name ?? 'Picker User'),

                                'picked_at' => $item->picked_at
                                    ? $item->picked_at->toIso8601String()
                                    : null,
                            ];
                        }

                        return null;

                    })
                    ->filter()
                    ->unique('name')
                    ->values();

                $packers = $order->items
                    ->map(function ($item) {

                        if ($item->packed_by || $item->packed_user_name) {

                            return [
                                'id' => $item->packed_by
                                    ? (int) $item->packed_by
                                    : null,

                                'name' => $item->packedUser
                                    ? $item->packedUser->name
                                    : ($item->packed_user_name ?? 'Packer User'),

                                'packed_at' => $item->packed_at
                                    ? $item->packed_at->toIso8601String()
                                    : null,
                            ];
                        }

                        return null;

                    })
                    ->filter()
                    ->unique('name')
                    ->values();

                return [
                    'order_id' => $order->id,

                    'order_number' => $order->order_number,

                    'status' => $order->status,

                    'bag_count' => (int) ($order->bag_count ?? 0),

                    'assigned_user' => $order->assigned_to ? [
                        'id' => (int) $order->assigned_to,

                        'name' => $order->assignedUser
                            ? $order->assignedUser->name
                            : ($order->assigned_user_name ?? 'Picker User'),

                        'assigned_at' => $order->assigned_at
                            ? $order->assigned_at->toIso8601String()
                            : null,
                    ] : null,

                    'driver_user' => ($order->delivered_by || $order->delivered_user_name)
                        ? [
                            'id' => $order->delivered_by
                                ? (int) $order->delivered_by
                                : null,

                            'name' => $order->deliveredUser
                                ? $order->deliveredUser->name
                                : ($order->delivered_user_name ?? 'Driver User'),

                            'delivered_at' => $order->delivered_at
                                ? $order->delivered_at->toIso8601String()
                                : null,
                        ]
                        : null,

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

                    'items' => $order->items
                        ->map(function ($item) {

                            return [
                                'item_id' => $item->id,

                                'line_item_id' => $item->line_item_id,

                                'product_id' => $item->product_id,

                                'product_code' => $item->product_code,

                                'barcode' => $item->barcode,

                                'product_name' => $item->product_name,

                                'quantity' => $item->quantity,

                                'unit_price' => (float) $item->unit_price,

                                'status' => $item->status,

                                'is_packer_verified' => (bool) $item->is_packer_verified,

                                'packer_verified_user' =>
                                    ($item->packer_verified_by || $item->packer_verified_user_name)
                                        ? [
                                            'id' => $item->packer_verified_by
                                                ? (int) $item->packer_verified_by
                                                : null,

                                            'name' => $item->packerVerifiedUser
                                                ? $item->packerVerifiedUser->name
                                                : ($item->packer_verified_user_name ?? 'Packer User'),

                                            'verified_at' => $item->packer_verified_at
                                                ? $item->packer_verified_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,
                                'is_flagged' => $item->is_flagged,
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

                                'picked_user' =>
                                    ($item->picked_by || $item->picked_user_name)
                                        ? [
                                            'id' => $item->picked_by
                                                ? (int) $item->picked_by
                                                : null,

                                            'name' => $item->pickedUser
                                                ? $item->pickedUser->name
                                                : ($item->picked_user_name ?? 'Picker User'),

                                            'picked_at' => $item->picked_at
                                                ? $item->picked_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,

                                'packed_user' =>
                                    ($item->packed_by || $item->packed_user_name)
                                        ? [
                                            'id' => $item->packed_by
                                                ? (int) $item->packed_by
                                                : null,

                                            'name' => $item->packedUser
                                                ? $item->packedUser->name
                                                : ($item->packed_user_name ?? 'Packer User'),

                                            'packed_at' => $item->packed_at
                                                ? $item->packed_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,

                                'delivered_user' =>
                                    ($item->delivered_by || $item->delivered_user_name)
                                        ? [
                                            'id' => $item->delivered_by
                                                ? (int) $item->delivered_by
                                                : null,

                                            'name' => $item->deliveredUser
                                                ? $item->deliveredUser->name
                                                : ($item->delivered_user_name ?? 'Driver User'),

                                            'delivered_at' => $item->delivered_at
                                                ? $item->delivered_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,

                                'picked_at' => $item->picked_at
                                    ? $item->picked_at->toIso8601String()
                                    : null,

                                'packed_at' => $item->packed_at
                                    ? $item->packed_at->toIso8601String()
                                    : null,

                                'delivered_at' => $item->delivered_at
                                    ? $item->delivered_at->toIso8601String()
                                    : null,
                            ];
                        })
                        ->values(),

                    'created_at' => $order->created_at
                        ? $order->created_at->toIso8601String()
                        : null,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Get all assigned orders and line item details for a specific user ID (or order ID)
     * Route: GET /api/orders/{id}
     *
     * Returns an array of orders with line items assigned to user_id that are NOT YET PICKED.
     */
    public function apiOrdersById(Request $request, $id)
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

        $userOrders = Order::with([
            'items' => function ($q) use ($idStr) {
                $q->where('assigned_to', $idStr)
                    ->where('status', 'pending');
            },

            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',

            'assignedUser',
            'deliveredUser',
            'logs.user',
        ])
        ->whereHas('items', function ($q) use ($idStr) {
            $q->where('assigned_to', $idStr)
                ->where('status', 'pending');
        })
        ->orderBy('updated_at', 'desc')
        ->get();

        if ($userOrders->isNotEmpty()) {

            $formattedOrders = $userOrders
                ->map(function ($order) {

                    $order->setRelation(
                        'items',
                        $order->items
                            ->filter(function ($item) {

                                return $item->status === 'pending';
                            })
                            ->values()
                    );

                    return $this->formatOrderDetails(
                        $order,
                        'unpicked'
                    );
                })
                ->filter(function ($order) {
                    return !empty($order['items']);
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $formattedOrders,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [],
        ]);
    }

    /**
     * Get completed/picked order details for a specific user ID where items were picked by that user
     * Route: GET /api/orders-complete/{id}
     *
     * Returns an array of orders & picked line items by user_id.
     */
    public function apiOrdersComplete(Request $request, $id)
    {
        $idStr = trim((string) $id);

        $completedOrders = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'deliveredUser',
            'logs.user'
        ])
        ->whereHas('items', function ($q) use ($idStr) {
            $q->where('picked_by', $idStr)
              ->orWhere(function ($sub) use ($idStr) {
                  $sub->where('assigned_to', $idStr)
                      ->whereIn('status', ['picked', 'packed', 'delivered']);
              });
        })
        ->orderBy('updated_at', 'desc')
        ->get();

        $formattedOrders = $completedOrders->map(fn($order) => $this->formatOrderDetails($order, 'picked'))
            ->filter(fn($ord) => count($ord['items']) > 0)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Assign order to picker ("Assign Me")
     * Route: POST /api/orders/assign-me
     */
    public function assignOrder(Request $request)
    {
        if ($request->filled('order_item_id') || $request->filled('order_item_ids') || $request->filled('line_item_id') || $request->filled('line_item_ids')) {
            return $this->assignItems($request);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
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

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');

        $dbUser = null;
        if ($userId) {
            $dbUser = \App\Models\User::find($userId);
        }

        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Picker User'));

        if (!$userId && !$userName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Pass user_id, user_name, or authenticate with Sanctum.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = (string) $request->input('order_id');

            $order = Order::with('items')
                ->where('id', $orderIdStr)
                ->orWhere('order_number', $orderIdStr)
                ->orWhere('order_number', ltrim($orderIdStr, '#'))
                ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                ->first();

            if (!$order) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                    $order = Order::with('items')
                        ->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', ltrim($orderIdStr, '#'))
                        ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                        ->first();
                } catch (\Exception $e) {
                    // Ignore sync exception if offline
                }
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found in database or Shopify.",
                ], 404);
            }

            $order->update([
                'assigned_to' => $userId,
                'assigned_user_name' => $userName,
                'assigned_at' => now(),
            ]);

            if ($order->status === 'pending') {
                $order->update(['status' => 'picking']);
            }

            foreach ($order->items as $item) {
                $item->update([
                    'assigned_to' => $userId,
                    'assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]);
            }

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => 'order_assigned_to_picker',
                'old_status' => $order->status,
                'new_status' => $order->status,
                'notes' => $request->input('notes', "Assigned entire order {$order->order_number} to picker {$userName}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} successfully assigned to picker {$userName}.",
                'data' => [
                    'assigned_user' => [
                        'id' => $userId,
                        'name' => $userName,
                    ],
                    'order' => $order->fresh(['items']),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Assign specific order item(s) to picker ("Assign Me Items")
     * Route: POST /api/orders/items/assign-me
     */
    public function assignItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'line_item_id' => 'nullable|string',
            'line_item_ids' => 'nullable|array',
            'line_item_ids.*' => 'nullable|string',
            'order_item_id' => 'nullable',
            'order_item_ids' => 'nullable|array',
            'order_item_ids.*' => 'nullable',
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

        $authUser = Auth::user();

        $userId = $authUser
            ? $authUser->id
            : $request->input('user_id');

        $dbUser = null;

        if ($userId) {
            $dbUser = \App\Models\User::find($userId);
        }

        $userName = $authUser
            ? $authUser->name
            : (
                $dbUser
                    ? $dbUser->name
                    : ($request->input('user_name') ?? 'Picker User')
            );

        if (!$userId && !$userName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Pass user_id, user_name, or authenticate with Sanctum.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = trim((string) $request->input('order_id'));
            $cleanOrderId = ltrim($orderIdStr, '#');

            $lineItemIds = [];

            if ($request->filled('line_item_id')) {
                $lineItemIds[] = (string) $request->input('line_item_id');
            }

            if ($request->filled('line_item_ids')) {
                foreach ((array) $request->input('line_item_ids') as $lineItemId) {
                    if ($lineItemId !== null && $lineItemId !== '') {
                        $lineItemIds[] = (string) $lineItemId;
                    }
                }
            }

            $orderItemIds = [];

            if ($request->filled('order_item_id')) {
                $orderItemIds[] = $request->input('order_item_id');
            }

            if ($request->filled('order_item_ids')) {
                foreach ((array) $request->input('order_item_ids') as $orderItemId) {
                    if ($orderItemId !== null && $orderItemId !== '') {
                        $orderItemIds[] = $orderItemId;
                    }
                }
            }

            if (empty($lineItemIds) && empty($orderItemIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide line_item_id, line_item_ids, order_item_id, or order_item_ids.',
                ], 422);
            }

            $order = Order::with('items')
                ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
                    $query->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', $cleanOrderId)
                        ->orWhere('order_number', '#' . $cleanOrderId);
                })
                ->first();

            if (!$order) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();

                    $order = Order::with('items')
                        ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
                            $query->where('id', $orderIdStr)
                                ->orWhere('order_number', $orderIdStr)
                                ->orWhere('order_number', $cleanOrderId)
                                ->orWhere('order_number', '#' . $cleanOrderId);
                        })
                        ->first();
                } catch (\Exception $e) {
                    \Log::error('Shopify order sync failed during item assignment', [
                        'order_id' => $orderIdStr,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found.",
                ], 404);
            }

            $itemsQuery = OrderItem::where('order_id', $order->id);

            if (!empty($lineItemIds) || !empty($orderItemIds)) {
                $itemsQuery->where(function ($q) use ($lineItemIds, $orderItemIds) {
                    if (!empty($lineItemIds)) {
                        $q->orWhereIn('line_item_id', $lineItemIds);
                    }
                    if (!empty($orderItemIds)) {
                        $q->orWhereIn('id', $orderItemIds);
                    }
                });
            }

            $items = $itemsQuery->get();

            if ($items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matching order items found for this order.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'requested_line_item_ids' => $lineItemIds,
                        'requested_order_item_ids' => $orderItemIds,
                    ],
                ], 404);
            }

            $alreadyAssignedItems = $items->filter(function ($item) {
                return !is_null($item->assigned_to);
            });

            if ($alreadyAssignedItems->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot assign item(s). One or more items are already assigned. Please unassign them first using /api/orders/items/unassign-me.',
                    'data' => [
                        'already_assigned_count' => $alreadyAssignedItems->count(),
                        'already_assigned_items' => $alreadyAssignedItems->map(function ($item) {
                            return [
                                'item_id' => $item->id,
                                'line_item_id' => $item->line_item_id,
                                'product_name' => $item->product_name,
                                'assigned_to' => $item->assigned_to,
                                'assigned_user_name' => $item->assigned_user_name,
                            ];
                        })->values(),
                    ],
                ], 400);
            }

            $oldOrderStatus = $order->status;
            $assignedItems = collect();

            foreach ($items as $item) {
                $oldItemStatus = $item->status;

                $item->update([
                    'assigned_to' => $userId,
                    'assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]);

                $assignedItems->push($item->fresh());

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'item_assigned_to_picker',
                    'old_status' => $oldItemStatus,
                    'new_status' => $oldItemStatus,
                    'notes' => $request->input(
                        'notes',
                        "Assigned item {$item->product_name} to picker {$userName}"
                    ),
                ]);
            }

            if (!$order->assigned_to) {
                $order->update([
                    'assigned_to' => $userId,
                    'assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]);
            }

            if ($order->status === 'pending') {
                $order->update([
                    'status' => 'picking',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully assigned {$assignedItems->count()} item(s) to picker {$userName}.",
                'data' => [
                    'assigned_user' => [
                        'id' => $userId,
                        'name' => $userName,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'old_status' => $oldOrderStatus,
                        'new_status' => $order->status,
                        'assigned_to' => $order->assigned_to,
                        'assigned_user_name' => $order->assigned_user_name,
                        'assigned_at' => $order->assigned_at,
                    ],
                    'items' => $assignedItems,
                ],
            ]);
        });
    }

    /**
     * Assign order or order items to current picker (Unified endpoint)
     */
    public function assignMe(Request $request)
    {
        if ($request->has('order_id') && !$request->has('order_item_id') && !$request->has('order_item_ids') && !$request->has('line_item_id') && !$request->has('line_item_ids')) {
            return $this->assignOrder($request);
        }
        return $this->assignItems($request);
    }

    /**
     * Unassign entire order and its unpicked items from picker ("Unassign Order")
     * Route: POST /api/orders/unassign or POST /api/orders/unassign-me
     */
    public function unassignOrder(Request $request)
    {
        if (!$request->filled('order_id') && ($request->filled('order_item_id') || $request->filled('order_item_ids') || $request->filled('line_item_id') || $request->filled('line_item_ids'))) {
            return $this->unassignItems($request);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
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

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');

        $dbUser = null;
        if ($userId) {
            $dbUser = \App\Models\User::find($userId);
        }

        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Picker User'));

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = (string) $request->input('order_id');

            $order = Order::with('items')
                ->where('id', $orderIdStr)
                ->orWhere('order_number', $orderIdStr)
                ->orWhere('order_number', ltrim($orderIdStr, '#'))
                ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                ->first();

            if (!$order) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                    $order = Order::with('items')
                        ->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', ltrim($orderIdStr, '#'))
                        ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                        ->first();
                } catch (\Exception $e) {
                    // Ignore sync exception if offline
                }
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found in database or Shopify.",
                ], 404);
            }

            $unassignedItems = collect();
            $skippedItems = collect();

            foreach ($order->items as $item) {
                $isPicked = in_array($item->status, ['picked', 'packed', 'delivered']) || !is_null($item->picked_by);

                if ($isPicked) {
                    $skippedItems->push([
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'status' => $item->status,
                        'reason' => 'Item has already been picked and cannot be unassigned.',
                    ]);
                } else {
                    $oldItemStatus = $item->status;
                    $item->update([
                        'assigned_to' => null,
                        'assigned_user_name' => null,
                        'assigned_at' => null,
                        'status' => ($item->status === 'picking') ? 'pending' : $item->status,
                    ]);

                    $unassignedItems->push($item->fresh());

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'action' => 'item_unassigned_from_picker',
                        'old_status' => $oldItemStatus,
                        'new_status' => $item->status,
                        'notes' => $request->input('notes', "Unassigned item {$item->product_name} from picker {$userName}"),
                    ]);
                }
            }

            $allItems = OrderItem::where('order_id', $order->id)->get();
            $hasAssignedItems = $allItems->whereNotNull('assigned_to')->count() > 0 || $allItems->whereNotNull('assigned_user_name')->count() > 0;
            $hasPickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count() > 0;

            if (!$hasAssignedItems && !$hasPickedItems) {
                $oldOrderStatus = $order->status;
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                    'status' => ($order->status === 'picking') ? 'pending' : $order->status,
                ]);

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'order_unassigned_from_picker',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input('notes', "Unassigned entire order {$order->order_number} from picker {$userName}"),
                ]);
            } elseif (!$hasAssignedItems) {
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                ]);
            }

            if ($unassignedItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "Order {$order->order_number} could not be unassigned because all items have already been picked.",
                    'data' => [
                        'order' => $order->fresh(['items']),
                        'skipped_items' => $skippedItems,
                    ],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} successfully unassigned ({$unassignedItems->count()} item(s) unassigned).",
                'data' => [
                    'order' => $order->fresh(['items']),
                    'unassigned_items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ],
            ]);
        });
    }

    /**
     * Unassign specific order item(s) from picker ("Unassign Items")
     * Route: POST /api/orders/items/unassign or POST /api/orders/items/unassign-me
     */
    public function unassignItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'line_item_id' => 'nullable|string',
            'line_item_ids' => 'nullable|array',
            'line_item_ids.*' => 'nullable|string',
            'order_item_id' => 'nullable',
            'order_item_ids' => 'nullable|array',
            'order_item_ids.*' => 'nullable',
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

        $authUser = Auth::user();
        $userId = $authUser ? $authUser->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $authUser ? $authUser->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Picker User'));

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = trim((string) $request->input('order_id'));
            $cleanOrderId = ltrim($orderIdStr, '#');

            $lineItemIds = [];
            if ($request->filled('line_item_id')) {
                $lineItemIds[] = (string) $request->input('line_item_id');
            }
            if ($request->filled('line_item_ids')) {
                foreach ((array) $request->input('line_item_ids') as $lineItemId) {
                    if ($lineItemId !== null && $lineItemId !== '') {
                        $lineItemIds[] = (string) $lineItemId;
                    }
                }
            }

            $orderItemIds = [];
            if ($request->filled('order_item_id')) {
                $orderItemIds[] = $request->input('order_item_id');
            }
            if ($request->filled('order_item_ids')) {
                foreach ((array) $request->input('order_item_ids') as $orderItemId) {
                    if ($orderItemId !== null && $orderItemId !== '') {
                        $orderItemIds[] = $orderItemId;
                    }
                }
            }

            $order = Order::with('items')
                ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
                    $query->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', $cleanOrderId)
                        ->orWhere('order_number', '#' . $cleanOrderId);
                })
                ->first();

            if (!$order) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                    $order = Order::with('items')
                        ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
                            $query->where('id', $orderIdStr)
                                ->orWhere('order_number', $orderIdStr)
                                ->orWhere('order_number', $cleanOrderId)
                                ->orWhere('order_number', '#' . $cleanOrderId);
                        })
                        ->first();
                } catch (\Exception $e) {
                    \Log::error('Shopify order sync failed during item unassignment', [
                        'order_id' => $orderIdStr,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found.",
                ], 404);
            }

            $itemsQuery = OrderItem::where('order_id', $order->id);

            if (!empty($lineItemIds) || !empty($orderItemIds)) {
                $itemsQuery->where(function ($q) use ($lineItemIds, $orderItemIds) {
                    if (!empty($lineItemIds)) {
                        $q->orWhereIn('line_item_id', $lineItemIds);
                    }
                    if (!empty($orderItemIds)) {
                        $q->orWhereIn('id', $orderItemIds);
                    }
                });
            }

            $items = $itemsQuery->get();

            if ($items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matching order items found for this order.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'requested_line_item_ids' => $lineItemIds,
                        'requested_order_item_ids' => $orderItemIds,
                    ],
                ], 404);
            }

            $unassignedItems = collect();
            $skippedItems = collect();

            foreach ($items as $item) {
                $isPicked = in_array($item->status, ['picked', 'packed', 'delivered']) || !is_null($item->picked_by);

                if ($isPicked) {
                    $skippedItems->push([
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'status' => $item->status,
                        'reason' => 'Item has already been picked and cannot be unassigned.',
                    ]);
                } else {
                    $oldItemStatus = $item->status;
                    $item->update([
                        'assigned_to' => null,
                        'assigned_user_name' => null,
                        'assigned_at' => null,
                        'status' => ($item->status === 'picking') ? 'pending' : $item->status,
                    ]);

                    $unassignedItems->push($item->fresh());

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'action' => 'item_unassigned_from_picker',
                        'old_status' => $oldItemStatus,
                        'new_status' => $item->status,
                        'notes' => $request->input('notes', "Unassigned item {$item->product_name} from picker {$userName}"),
                    ]);
                }
            }

            $allItems = OrderItem::where('order_id', $order->id)->get();
            $hasAssignedItems = $allItems->whereNotNull('assigned_to')->count() > 0 || $allItems->whereNotNull('assigned_user_name')->count() > 0;
            $hasPickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count() > 0;

            if (!$hasAssignedItems && !$hasPickedItems) {
                $oldOrderStatus = $order->status;
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                    'status' => ($order->status === 'picking') ? 'pending' : $order->status,
                ]);

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'order_unassigned_from_picker',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input('notes', "Unassigned order {$order->order_number} as all items are now unassigned"),
                ]);
            } elseif (!$hasAssignedItems) {
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                ]);
            }

            if ($unassignedItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No items could be unassigned because they have already been picked.',
                    'data' => [
                        'order' => [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'status' => $order->status,
                        ],
                        'skipped_items' => $skippedItems,
                    ],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully unassigned {$unassignedItems->count()} item(s).",
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'assigned_to' => $order->assigned_to,
                        'assigned_user_name' => $order->assigned_user_name,
                        'assigned_at' => $order->assigned_at,
                    ],
                    'items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ],
            ]);
        });
    }

    /**
     * Unassign order or order items from current picker (Unified endpoint)
     * Route: POST /api/orders/unassign-me
     */
    public function unassignMe(Request $request)
    {
        if ($request->has('order_id') && !$request->has('order_item_id') && !$request->has('order_item_ids') && !$request->has('line_item_id') && !$request->has('line_item_ids')) {
            return $this->unassignOrder($request);
        }
        return $this->unassignItems($request);
    }

    /**
     * Update individual item status (picked, packed, delivered) with user log
     * Route: POST /api/orders/items/update-status
     */
    public function updateItemStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_item_id' => 'required_without_all:line_item_id,barcode,product_code|nullable',
            'line_item_id' => 'nullable|string',
            'order_id' => 'nullable',
            'barcode' => 'nullable|string',
            'product_code' => 'nullable|string',
            'status' => 'required|in:pending,picked,packed,delivered,cancelled',
            'notes' => 'nullable|string',
            'user_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $query = OrderItem::query();

            if ($request->filled('order_item_id')) {
                $query->where('id', $request->input('order_item_id'));
            } elseif ($request->filled('line_item_id')) {
                $query->where('line_item_id', $request->input('line_item_id'));
            } elseif ($request->filled('order_id')) {
                $query->where('order_id', $request->input('order_id'));
                if ($request->filled('barcode')) {
                    $query->where(function ($q) use ($request) {
                        $q->where('barcode', $request->input('barcode'))
                          ->orWhere('product_code', $request->input('barcode'))
                          ->orWhere('line_item_id', $request->input('barcode'));
                    });
                } elseif ($request->filled('product_code')) {
                    $query->where('product_code', $request->input('product_code'));
                }
            } elseif ($request->filled('barcode')) {
                $query->where('barcode', $request->input('barcode'))
                      ->orWhere('product_code', $request->input('barcode'))
                      ->orWhere('line_item_id', $request->input('barcode'));
            }

            $orderItem = $query->first();

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order item not found for the provided criteria.',
                ], 404);
            }

            if (!$orderItem->assigned_to) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot update status. Item '{$orderItem->product_name}' is not assigned to any picker.",
                ], 400);
            }

            $oldStatus = $orderItem->status;
            $newStatus = $request->input('status');

            $user = Auth::user();
            $userId = $user ? $user->id : $request->input('user_id');
            $dbUser = $userId ? \App\Models\User::find($userId) : null;
            $validUserId = $dbUser ? $dbUser->id : null;
            $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

            if ($newStatus === 'picked') {
                $orderItem->picked_by = $validUserId;
                $orderItem->picked_user_name = $userName;
                if (!$orderItem->picked_at) {
                    $orderItem->picked_at = now();
                }
            } elseif ($newStatus === 'packed') {
                $orderItem->packed_by = $validUserId;
                $orderItem->packed_user_name = $userName;
                if (!$orderItem->packed_at) {
                    $orderItem->packed_at = now();
                }
            } elseif ($newStatus === 'delivered') {
                $orderItem->delivered_by = $validUserId;
                $orderItem->delivered_user_name = $userName;
                if (!$orderItem->delivered_at) {
                    $orderItem->delivered_at = now();
                }
            }

            $orderItem->status = $newStatus;
            $orderItem->save();

            $log = OrderStatusLog::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'item_status_updated',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $request->input('notes', "Updated item status for {$orderItem->product_name}"),
            ]);

            $order = Order::with('items')->find($orderItem->order_id);
            $allStatuses = $order->items->pluck('status');

            $newOrderStatus = $order->status;
            if ($allStatuses->every(fn($s) => $s === 'delivered')) {
                $newOrderStatus = 'delivered';
            } elseif ($allStatuses->every(fn($s) => in_array($s, ['packed', 'delivered']))) {
                $newOrderStatus = 'packed';
            } elseif ($allStatuses->every(fn($s) => in_array($s, ['picked', 'packed', 'delivered']))) {
                $newOrderStatus = 'picked';
            } elseif ($allStatuses->contains(fn($s) => in_array($s, ['picked', 'packed', 'delivered']))) {
                $newOrderStatus = 'picking';
            }

            if ($newOrderStatus !== $order->status) {
                $oldOrderStatus = $order->status;
                $order->status = $newOrderStatus;

                if ($newOrderStatus === 'delivered') {
                    $order->delivered_by = $validUserId;
                    $order->delivered_user_name = $userName;
                    $order->delivered_at = now();
                }

                $order->save();

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $validUserId,
                    'user_name' => $userName,
                    'action' => 'order_status_auto_updated',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $newOrderStatus,
                    'notes' => "Order overall status updated to {$newOrderStatus} after item status update.",
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Order item status updated from '{$oldStatus}' to '{$newStatus}'.",
                'data' => [
                    'order_item' => $orderItem->fresh(),
                    'order' => $order->fresh(),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Helper method to format order & items details consistently for response
     */
    protected function formatOrderDetails(Order $order, $filterStatus = null)
    {
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

        $items = $order->items;
        if ($filterStatus === 'unpicked') {
            $items = $items->filter(fn($item) => $item->status === 'pending');
        } elseif ($filterStatus === 'picked') {
            $items = $items->filter(fn($item) => in_array($item->status, ['picked', 'packed', 'delivered']));
        } elseif ($filterStatus === 'packed') {
            $items = $items->filter(fn($item) => in_array($item->status, ['packed', 'delivered']));
        }

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
                'total_items' => $order->items->count(),
                'picked_items' => $order->items->where('status', 'picked')->count(),
                'packed_items' => $order->items->where('status', 'packed')->count(),
                'delivered_items' => $order->items->where('status', 'delivered')->count(),
            ],
            'items' => $items->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'line_item_id' => $item->line_item_id,
                    'product_id' => $item->product_id,
                    'product_code' => $item->product_code,
                    'barcode' => $item->barcode,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'status' => $item->status,
                    'is_packer_verified' => (bool) $item->is_packer_verified,
                    'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
                        'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
                        'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
                        'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                    ] : null,
                    'is_flagged' => $item->is_flagged,
                    'flag_reason' => $item->flag_reason,
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
            })->values(),
            'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
        ];
    }
}
