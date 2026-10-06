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


class PickerAdminController extends Controller
{
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
}
