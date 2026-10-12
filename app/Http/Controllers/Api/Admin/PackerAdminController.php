<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackerResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderItem;
use App\Models\OrderPackerAssigned;
use App\Models\OrderStatusLog;
use App\Models\PackerVerification;



class PackerAdminController extends Controller
{
    /**
     * Get list of packers from users table.
     * Route: GET /api/packers
     * Route: GET /api/get-packers-list
     * Route: GET /api/users/packers
     */
 public function getPackersList(): JsonResponse
    {
        try {
            $packers = User::where('role', 'packer')
                ->orWhere('role', 'Packer')
                ->orWhereHas('roles', function ($query) {
                    $query->whereIn('name', ['packer', 'Packer']);
                })
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Packers list retrieved successfully.',
                'count' => $packers->count(),
                'data' => $packers
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch packers list: ' . $e->getMessage()
            ], 500);
        }
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
            'order_id'    => 'required',
            'order_items' => 'nullable|array',
            'packer_id'   => 'nullable',
            'packer_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Packer Details
        |--------------------------------------------------------------------------
        */
        $authUser = Auth::user();

        $globalUserId = $request->input('packer_id');

        if ($globalUserId && is_numeric($globalUserId)) {
            $globalUserId = (int) $globalUserId;
        }

        $dbUser = $globalUserId
            ? \App\Models\User::find($globalUserId)
            : null;

        $globalUserName = $request->input('packer_name')
            ?? ($dbUser ? $dbUser->name : null)
            ?? ($authUser ? $authUser->name : null);

        /*
        |--------------------------------------------------------------------------
        | Packer ID / Name Validation
        |--------------------------------------------------------------------------
        */
        if (!$globalUserId && !$globalUserName) {
            return response()->json([
                'success' => false,
                'message' => 'Packer identification required. Please pass packer_id or packer_name.',
            ], 422);
        }

        return DB::transaction(function () use (
            $request,
            $authUser,
            $globalUserId,
            $globalUserName
        ) {

            /*
            |--------------------------------------------------------------------------
            | Build Order Payload
            |--------------------------------------------------------------------------
            */
            $ordersPayload = [
                [
                    'order_id'    => $request->input('order_id'),
                    'order_items' => $request->input('order_items', []),
                    'packer_id'   => $request->input('packer_id'),
                    'packer_name' => $request->input('packer_name'),
                ]
            ];

            $processedOrders = [];
            $totalAssignedItemsCount = 0;

            /*
            |--------------------------------------------------------------------------
            | Process Order
            |--------------------------------------------------------------------------
            */
            foreach ($ordersPayload as $orderEntry) {

                $orderIdRaw = trim(
                    (string) ($orderEntry['order_id'] ?? '')
                );

                if (empty($orderIdRaw)) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Resolve Packer
                |--------------------------------------------------------------------------
                */
                $entryUserId = $orderEntry['packer_id'] ?? $globalUserId;

                if ($entryUserId && is_numeric($entryUserId)) {
                    $entryUserId = (int) $entryUserId;
                }

                $entryDbUser = $entryUserId
                    ? \App\Models\User::find($entryUserId)
                    : null;

                $entryUserName = $orderEntry['packer_name']
                    ?? ($entryDbUser ? $entryDbUser->name : null)
                    ?? $globalUserName;

                if (!$entryUserName) {
                    $entryUserName = 'Packer User';
                }

                /*
                |--------------------------------------------------------------------------
                | Find Order
                |--------------------------------------------------------------------------
                */
                $cleanOrderId = ltrim($orderIdRaw, '#');

                $order = Order::with('items')
                    ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {

                        $q->where('id', $orderIdRaw)
                            ->orWhere('order_number', $orderIdRaw)
                            ->orWhere('order_number', $cleanOrderId)
                            ->orWhere('order_number', '#' . $cleanOrderId);
                    })
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Try Shopify Sync If Order Not Found
                |--------------------------------------------------------------------------
                */
                if (!$order) {

                    try {

                        $this->shopifyService->syncOrdersToDatabase();

                        $order = Order::with('items')
                            ->where(function ($q) use (
                                $orderIdRaw,
                                $cleanOrderId
                            ) {

                                $q->where('id', $orderIdRaw)
                                    ->orWhere('order_number', $orderIdRaw)
                                    ->orWhere('order_number', $cleanOrderId)
                                    ->orWhere('order_number', '#' . $cleanOrderId);
                            })
                            ->first();

                    } catch (\Exception $e) {

                        \Log::warning(
                            'Shopify sync failed while assigning packer',
                            [
                                'order_id' => $orderIdRaw,
                                'error' => $e->getMessage(),
                            ]
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Order Not Found
                |--------------------------------------------------------------------------
                */
                if (!$order) {

                    return response()->json([
                        'success' => false,
                        'message' => "Order '{$orderIdRaw}' not found in system.",
                    ], 404);
                }

                /*
                |--------------------------------------------------------------------------
                | Get Order Items Input
                |--------------------------------------------------------------------------
                |
                | Example:
                |
                | "order_items": [
                |     "16597827420404"
                | ]
                |
                | The value may be:
                |
                | 1. Local OrderItem.id
                | 2. Shopify line_item_id
                |
                */
                $itemsInput = $orderEntry['order_items'] ?? [];

                if (!is_array($itemsInput)) {
                    $itemsInput = [$itemsInput];
                }

                /*
                |--------------------------------------------------------------------------
                | Clean Item IDs
                |--------------------------------------------------------------------------
                */
                $itemsInput = collect($itemsInput)
                    ->filter(function ($value) {
                        return $value !== null && $value !== '';
                    })
                    ->map(function ($value) {

                        /*
                        | Handle values such as:
                        | "16597827420404"
                        | 16597827420404
                        | "#16597827420404"
                        */
                        return trim(
                            ltrim((string) $value, '#')
                        );
                    })
                    ->filter(function ($value) {
                        return $value !== '';
                    })
                    ->unique()
                    ->values()
                    ->toArray();

                /*
                |--------------------------------------------------------------------------
                | Find Order Items
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | We directly check BOTH:
                |
                | OrderItem.id
                | OrderItem.line_item_id
                |
                | So Shopify line_item_id values work correctly.
                |
                */
                $itemsQuery = OrderItem::where(
                    'order_id',
                    $order->id
                );

                /*
                |--------------------------------------------------------------------------
                | If Specific Items Were Supplied
                |--------------------------------------------------------------------------
                */
                if (!empty($itemsInput)) {

                    $itemsQuery->where(function ($q) use ($itemsInput) {

                        /*
                        | Local OrderItem ID
                        */
                        $q->whereIn(
                            'id',
                            $itemsInput
                        );

                        /*
                        | Shopify Line Item ID
                        */
                        $q->orWhereIn(
                            'line_item_id',
                            $itemsInput
                        );
                    });
                }

                $items = $itemsQuery->get();

                /*
                |--------------------------------------------------------------------------
                | No Items Found
                |--------------------------------------------------------------------------
                */
                if ($items->isEmpty()) {

                    return response()->json([
                        'success' => false,
                        'message' => 'No matching order items found for this order.',
                        'debug' => [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'order_items_received' => $itemsInput,
                        ],
                    ], 404);
                }

                /*
                |--------------------------------------------------------------------------
                | Assign Items To Packer
                |--------------------------------------------------------------------------
                */
                $assignedItems = collect();

                $oldOrderStatus = $order->status;

                $nowTimestamp = now();

                foreach ($items as $item) {

                    $oldItemStatus = $item->status;

                    /*
                    |--------------------------------------------------------------------------
                    | Assign Packer To Order Item
                    |--------------------------------------------------------------------------
                    */
                    $item->update([
                        'packed_by' => $entryUserId,
                        'packed_user_name' => $entryUserName,
                        'packed_at' => $nowTimestamp,
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Refresh Item
                    |--------------------------------------------------------------------------
                    */
                    $freshItem = $item->fresh();

                    $assignedItems->push(
                        $freshItem
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Item Assignment Log
                    |--------------------------------------------------------------------------
                    */
                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $entryUserId,
                        'user_name' => $entryUserName,
                        'action' => 'item_assigned_to_packer',
                        'old_status' => $oldItemStatus,
                        'new_status' => $oldItemStatus,
                        'notes' => "Assigned item {$item->product_name} to packer {$entryUserName}",
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Update Order Packer Details
                |--------------------------------------------------------------------------
                */
                $order->update([
                    'packed_by' => $entryUserId,
                    'packed_user_name' => $entryUserName,
                    'packed_at' => $nowTimestamp,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Update OrderPackerAssigned
                |--------------------------------------------------------------------------
                */
                OrderPackerAssigned::updateOrCreate(
                    [
                        'order_id' => $order->id,
                    ],
                    [
                        'packer_assigned_user_id' => $entryUserId,
                        'packer_assigned_user_name' => $entryUserName,
                        'assigned_at' => $nowTimestamp,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Update Order Status
                |--------------------------------------------------------------------------
                */
                if (in_array($order->status, [
                    'picked',
                    'picking'
                ])) {

                    $order->update([
                        'status' => 'packing',
                    ]);

                    /*
                    | Refresh order so response contains new status
                    */
                    $order->refresh();
                }

                /*
                |--------------------------------------------------------------------------
                | Order Assignment Log
                |--------------------------------------------------------------------------
                */
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $entryUserId,
                    'user_name' => $entryUserName,
                    'action' => 'packer_assigned_to_order',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => "Assigned order {$order->order_number} to packer {$entryUserName} with {$assignedItems->count()} item(s)",
                ]);

                /*
                |--------------------------------------------------------------------------
                | Count Assigned Items
                |--------------------------------------------------------------------------
                */
                $totalAssignedItemsCount +=
                    $assignedItems->count();

                /*
                |--------------------------------------------------------------------------
                | Response Order
                |--------------------------------------------------------------------------
                */
                $processedOrders[] = [
                    'order_id' => $order->id,

                    'order_number' => $order->order_number,

                    'old_status' => $oldOrderStatus,

                    'new_status' => $order->status,

                    'packed_by' => $order->packed_by,

                    'packed_user_name' => $order->packed_user_name,

                    'packed_at' => $order->packed_at
                        ? $order->packed_at->toIso8601String()
                        : null,

                    'assigned_items_count' =>
                        $assignedItems->count(),

                    'assigned_items' =>
                        $assignedItems,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Final Response
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'success' => true,

                'message' =>
                    'Order successfully assigned to packer with order items.',

                'data' => [

                    'packer' => [
                        'id' => $globalUserId,
                        'name' => $globalUserName,
                    ],

                    'assigned_orders_count' =>
                        count($processedOrders),

                    'total_assigned_items_count' =>
                        $totalAssignedItemsCount,

                    'orders' =>
                        $processedOrders,
                ],
            ]);
        });
    }
}
