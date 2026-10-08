<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PickerResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\OrderItem;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\OrderStatusLog;
use App\Services\ShopifyService;



class PickerAdminController extends Controller
{
    protected ?ShopifyService $shopifyService = null;

    public function __construct(?ShopifyService $shopifyService = null)
    {
        $this->shopifyService = $shopifyService;
    }

     /**
     * Get list of pickers from users table.
     * Route: GET /api/pickers
     * Route: GET /api/get-pickers-list
     * Route: GET /api/users/pickers
     */
    public function getPickersList(): JsonResponse
    {
        try {
            $pickers = User::where('role', 'picker')
                ->orWhere('role', 'Picker')
                ->orWhereHas('roles', function ($query) {
                    $query->whereIn('name', ['picker', 'Picker']);
                })
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Pickers list retrieved successfully.',
                'count' => $pickers->count(),
                'data' => $pickers
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch pickers list: ' . $e->getMessage()
            ], 500);
        }
    }
    public function assignItemsByPicker(Request $request)
    {
        try {

            $validated = $request->validate([
                'order_line_item_id' => 'required|exists:order_items,line_item_id',
                'picker_id' => 'required|exists:users,id',
            ]);

            DB::beginTransaction();

            $order_line_item_id = $validated['order_line_item_id'];
            $picker_id = $validated['picker_id'];

            // Get picker
            $picker = User::findOrFail($picker_id);

            // Get selected order item
            $order_line_item = OrderItem::where(
                'line_item_id',
                $order_line_item_id
            )->firstOrFail();

            // Assign picker to this item
            $order_line_item->assigned_to = $picker->id;
            $order_line_item->assigned_user_name = $picker->name;
            $order_line_item->assigned_at = now();
            $order_line_item->status = 'picker_assigned';

            $order_line_item->save();

            /*
            |--------------------------------------------------------------------------
            | Get the parent order
            |--------------------------------------------------------------------------
            */

            $order_id = $order_line_item->order_id;

            /*
            |--------------------------------------------------------------------------
            | Get all items belonging to this order
            |--------------------------------------------------------------------------
            */

            $all_order_items = OrderItem::where(
                'order_id',
                $order_id
            )->get();

            /*
            |--------------------------------------------------------------------------
            | Check if all items are assigned
            |--------------------------------------------------------------------------
            */

            $unassigned_items = $all_order_items->filter(function ($item) {

                return empty($item->assigned_to);

            });

            $all_items_assigned = $unassigned_items->isEmpty();

            /*
            |--------------------------------------------------------------------------
            | If this is the last item, update parent order
            |--------------------------------------------------------------------------
            */

            $order = Order::findOrFail($order_id);

            if ($all_items_assigned) {

                $order->assigned_to = $picker->id;
                $order->assigned_user_name = $picker->name;
                $order->assigned_at = now();

                $order->save();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $all_items_assigned
                    ? 'Last item assigned. Order assigned to picker successfully.'
                    : 'Item assigned to picker successfully.',
                'all_items_assigned' => $all_items_assigned,
                'order_id' => $order_id,
                'data' => $order_line_item
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign items to picker: ' . $e->getMessage()
            ], 500);
        }
    }
    public function unassignItemsByPicker(Request $request)
    {
        try {

            $validated = $request->validate([
                'order_line_item_id' => 'required|exists:order_items,line_item_id',
            ]);

            DB::beginTransaction();

            $order_line_item_id = $validated['order_line_item_id'];

            /*
            |--------------------------------------------------------------------------
            | Get selected order item
            |--------------------------------------------------------------------------
            */

            $order_line_item = OrderItem::where(
                'line_item_id',
                $order_line_item_id
            )->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Get parent order ID BEFORE unassigning
            |--------------------------------------------------------------------------
            */

            $order_id = $order_line_item->order_id;

            /*
            |--------------------------------------------------------------------------
            | Unassign this item
            |--------------------------------------------------------------------------
            */

            $order_line_item->assigned_to = null;
            $order_line_item->assigned_user_name = null;
            $order_line_item->assigned_at = null;
            $order_line_item->status = 'pending';

            $order_line_item->save();

            /*
            |--------------------------------------------------------------------------
            | Check all items belonging to this order
            |--------------------------------------------------------------------------
            */

            $all_order_items = OrderItem::where(
                'order_id',
                $order_id
            )->get();

            /*
            |--------------------------------------------------------------------------
            | Check whether any item is still assigned
            |--------------------------------------------------------------------------
            */

            $assigned_items = $all_order_items->filter(function ($item) {

                return !empty($item->assigned_to);

            });

            $no_items_assigned = $assigned_items->isEmpty();

            /*
            |--------------------------------------------------------------------------
            | If this was the last assigned item,
            | clear assignment from parent order
            |--------------------------------------------------------------------------
            */

            $order = Order::findOrFail($order_id);

            if ($no_items_assigned) {

                $order->assigned_to = null;
                $order->assigned_user_name = null;
                $order->assigned_at = null;
                $order->status = 'pending';
                $order->save();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $no_items_assigned
                    ? 'Last assigned item unassigned. Order assignment cleared successfully.'
                    : 'Item unassigned from picker successfully.',
                'no_items_assigned' => $no_items_assigned,
                'order_id' => $order_id,
                'data' => $order_line_item
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to unassign items from picker: ' . $e->getMessage()
            ], 500);
        }
    }
    public function assignOrderWithItems(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'order_id' => 'required',
                'picker_id' => 'required|exists:users,id',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            return DB::transaction(function () use ($request) {

                /*
                |--------------------------------------------------------------------------
                | Get picker
                |--------------------------------------------------------------------------
                */

                $picker = User::findOrFail($request->picker_id);

                $pickerId = $picker->id;
                $pickerName = $picker->name;

                /*
                |--------------------------------------------------------------------------
                | Find Order
                |--------------------------------------------------------------------------
                */

                $orderIdRaw = trim((string) $request->order_id);

                $cleanOrderId = ltrim($orderIdRaw, '#');

                $order = Order::where(function ($q) use ($orderIdRaw, $cleanOrderId) {

                    $q->where('order_number', $orderIdRaw)
                      ->orWhere('order_number', '#' . $cleanOrderId);
                })->first();

                /*
                |--------------------------------------------------------------------------
                | If order not found, try syncing Shopify orders
                |--------------------------------------------------------------------------
                */

                if (!$order) {

                    try {
                        $order = Order::where(function ($q) use ($orderIdRaw, $cleanOrderId) {

                            $q->where('order_number', $orderIdRaw)
                                ->orWhere('order_number', '#' . $cleanOrderId);
                        })->first();

                    } catch (\Exception $e) {

                        // Continue and return not found below

                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Order not found
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
                | Get ALL items belonging to this order
                |--------------------------------------------------------------------------
                */

                $items = OrderItem::where(
                    'order_id',
                    $order->id
                )->get();

                /*
                |--------------------------------------------------------------------------
                | No items found
                |--------------------------------------------------------------------------
                */

                if ($items->isEmpty()) {

                    return response()->json([
                        'success' => false,
                        'message' => 'No order items found for this order.',
                        'order_id' => $order->id,
                    ], 404);
                }

                /*
                |--------------------------------------------------------------------------
                | Store old order status
                |--------------------------------------------------------------------------
                */

                $oldOrderStatus = $order->status;

                /*
                |--------------------------------------------------------------------------
                | Assign picker to ALL order items
                |--------------------------------------------------------------------------
                */

                $assignedItems = collect();

                foreach ($items as $item) {

                    $oldItemStatus = $item->status;

                    $item->update([
                        'assigned_to' => $pickerId,
                        'assigned_user_name' => $pickerName,
                        'assigned_at' => now(),
                        'status' => 'picker_assigned',
                    ]);

                    $assignedItems->push($item->fresh());

                    /*
                    |--------------------------------------------------------------------------
                    | Item assignment log
                    |--------------------------------------------------------------------------
                    */

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $pickerId,
                        'user_name' => $pickerName,
                        'action' => 'item_assigned_to_picker',
                        'old_status' => $oldItemStatus,
                        'new_status' => 'picker_assigned',
                        'notes' => $request->input(
                            'notes',
                            "Assigned item {$item->product_name} to picker {$pickerName}"
                        ),
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Update parent ORDER assignment
                |--------------------------------------------------------------------------
                */

                $order->update([
                    'assigned_to' => $pickerId,
                    'assigned_user_name' => $pickerName,
                    'assigned_at' => now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Change order status
                |--------------------------------------------------------------------------
                */

                if ($order->status === 'pending') {

                    $order->update([
                        'status' => 'picking',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Order assignment log
                |--------------------------------------------------------------------------
                */

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $pickerId,
                    'user_name' => $pickerName,
                    'action' => 'order_assigned_to_picker',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input(
                        'notes',
                        "Assigned order {$order->order_number} to picker {$pickerName} with {$assignedItems->count()} item(s)"
                    ),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Return response
                |--------------------------------------------------------------------------
                */

                return response()->json([
                    'success' => true,
                    'message' => 'Order and all items successfully assigned to picker.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,

                        'picker' => [
                            'id' => $pickerId,
                            'name' => $pickerName,
                        ],

                        'assigned_to' => $order->assigned_to,
                        'assigned_user_name' => $order->assigned_user_name,
                        'assigned_at' => $order->assigned_at
                            ? $order->assigned_at->toIso8601String()
                            : null,

                        'old_status' => $oldOrderStatus,
                        'new_status' => $order->status,

                        'assigned_items_count' => $assignedItems->count(),

                        'assigned_items' => $assignedItems,
                    ],
                ]);
            });

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign order to picker: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Helper to extract item IDs and line item IDs from request inputs.
     */
    protected function extractItemIdentifiers($itemsInput): array
    {
        $itemIds = [];
        $lineItemIds = [];

        if (!is_array($itemsInput)) {
            return ['item_ids' => [], 'line_item_ids' => []];
        }

        foreach ($itemsInput as $raw) {
            if (is_numeric($raw) || is_string($raw)) {
                $val = trim((string) $raw);
                if ($val !== '' && $val !== '*' && strtolower($val) !== 'all') {
                    if (is_numeric($val)) {
                        $itemIds[] = (int) $val;
                    }
                    $lineItemIds[] = $val;
                }
            } elseif (is_array($raw)) {
                if (isset($raw['id']) && $raw['id'] !== null && $raw['id'] !== '') {
                    if (is_numeric($raw['id'])) {
                        $itemIds[] = (int) $raw['id'];
                    }
                    $lineItemIds[] = (string) $raw['id'];
                }
                if (isset($raw['order_item_id']) && $raw['order_item_id'] !== null && $raw['order_item_id'] !== '') {
                    if (is_numeric($raw['order_item_id'])) {
                        $itemIds[] = (int) $raw['order_item_id'];
                    }
                    $lineItemIds[] = (string) $raw['order_item_id'];
                }
                if (isset($raw['line_item_id']) && $raw['line_item_id'] !== null && $raw['line_item_id'] !== '') {
                    $lineItemIds[] = (string) $raw['line_item_id'];
                }
            }
        }

        return [
            'item_ids' => array_values(array_unique($itemIds)),
            'line_item_ids' => array_values(array_unique($lineItemIds)),
        ];
    }

public function unassignOrderWithItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'nullable|array',
            'order_id' => 'required_without:orders',
            'order_items' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'items' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'picker_id' => 'nullable',
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
        $userId = $authUser ? $authUser->id : ($request->input('picker_id') ?? $request->input('user_id'));
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
                        if ($this->shopifyService) { $this->shopifyService->syncOrdersToDatabase(); }
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
                    $isPicked = in_array($item->status, ['picked', 'packed', 'delivered']) || !is_null($item->picked_by);

                    if ($isPicked) {
                        $skippedItems->push([
                            'item_id' => $item->id,
                            'line_item_id' => $item->line_item_id,
                            'product_name' => $item->product_name,
                            'status' => $item->status,
                            'reason' => 'Item has already been picked/packed and cannot be unassigned.',
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
                            'notes' => $request->input('notes', "Unassigned item {$item->product_name} from picker"),
                        ]);
                    }
                }

                // Evaluate order assignment status after items unassignment
                $allItems = OrderItem::where('order_id', $order->id)->get();
                $hasAssignedItems = $allItems->whereNotNull('assigned_to')->count() > 0 || $allItems->whereNotNull('assigned_user_name')->count() > 0;
                $hasPickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count() > 0;

                $oldOrderStatus = $order->status;
                if (!$hasAssignedItems && !$hasPickedItems) {
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

                $totalUnassignedItemsCount += $unassignedItems->count();

                $processedOrders[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'assigned_to' => $order->assigned_to,
                    'assigned_user_name' => $order->assigned_user_name,
                    'unassigned_items_count' => $unassignedItems->count(),
                    'unassigned_items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "Order items successfully unassigned ({$totalUnassignedItemsCount} item(s) unassigned across " . count($processedOrders) . " order(s)).",
                'data' => [
                    'unassigned_orders_count' => count($processedOrders),
                    'total_unassigned_items_count' => $totalUnassignedItemsCount,
                    'orders' => $processedOrders,
                ],
            ]);
        });
    }
}

