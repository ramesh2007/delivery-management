<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDriverAssigned;
use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class DriverManagementController extends Controller
{
    /**
     * Get list of drivers.
     */
    public function index(): JsonResponse
    {
        $drivers = User::where('role', 'driver')
            ->orWhere('role', 'Driver')
            ->orWhereHas('roles', function ($query) {
                $query->whereIn('name', ['driver', 'Driver']);
            })
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $drivers
        ]);
    }

    /**
     * Assign driver to an order (React.js Frontend API).
     */
    public function assignDriver(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_number' => 'nullable|string',
                'order_id' => 'nullable|integer',
                'assigned_driver_user_id' => 'required|exists:users,id',
                'zone' => 'nullable|string',
            ]);

            if (empty($validated['order_number']) && empty($validated['order_id'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Either order_number or order_id is required.'
                ], 422);
            }

            // Find order
            $orderQuery = Order::query();
            if (!empty($validated['order_id'])) {
                $orderQuery->where('id', $validated['order_id']);
            } else {
                $orderQuery->where('order_number', $validated['order_number']);
            }
            $order = $orderQuery->first();

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found.'
                ], 404);
            }

            // Find driver user
            $driver = User::find($validated['assigned_driver_user_id']);

            $oldStatus = $order->status;
            $newStatus = 'assigned_to_driver';

            // Create or update driver assignment in orders_driver_assigned table
            $assignment = OrderDriverAssigned::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'order_number' => $order->order_number,
                    'assigned_driver_user_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'zone' => $validated['zone'] ?? null,
                    'order_status' => $newStatus,
                    'driver_status' => 'assigned',
                    'assigned_at' => now(),
                ]
            );

            // Update main order status to 'assigned_to_driver'
            $order->update([
                'status' => $newStatus,
            ]);

            // Log status audit
            OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id() ?? $driver->id,
                'user_name' => auth()->user()?->name ?? 'System',
                'action' => 'driver_assigned',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => "Assigned to driver: {$driver->name} (User ID: {$driver->id})" . (!empty($validated['zone']) ? " [Zone: {$validated['zone']}]" : ""),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Driver assigned successfully.',
                'data' => $assignment->load(['order.items', 'driver'])
            ], 200);

        } catch (Exception $e) {
            Log::error('Assign Driver Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign driver: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assigned orders for driver (Flutter App API using driver user id).
     */
    public function getAssignedOrders(Request $request, $driver_user_id): JsonResponse
    {
        try {
            $driver = User::find($driver_user_id);
            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver user not found.'
                ], 404);
            }

            $query = OrderDriverAssigned::where('assigned_driver_user_id', $driver_user_id)
                ->with(['order.items']);

            // Optional driver status filter
            if ($request->has('driver_status')) {
                $query->where('driver_status', $request->query('driver_status'));
            }

            $assignments = $query->orderBy('updated_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'driver_user_id' => (int)$driver_user_id,
                'driver_name' => $driver->name,
                'count' => $assignments->count(),
                'data' => $assignments
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Assigned Orders Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve assigned orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update driver status & timestamps (Flutter App API).
     */
    public function updateDriverStatus(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_number' => 'nullable|string',
                'order_id' => 'nullable|integer',
                'assignment_id' => 'nullable|integer',
                'driver_status' => 'required|in:assigned,accepted,started,delivered,cancelled,refund,exchange',
                'notes' => 'nullable|string',
            ]);

            $assignment = null;

            if (!empty($validated['assignment_id'])) {
                $assignment = OrderDriverAssigned::find($validated['assignment_id']);
            } elseif (!empty($validated['order_id'])) {
                $assignment = OrderDriverAssigned::where('order_id', $validated['order_id'])->first();
            } elseif (!empty($validated['order_number'])) {
                $assignment = OrderDriverAssigned::where('order_number', $validated['order_number'])->first();
            }

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver assignment not found.'
                ], 404);
            }

            $oldDriverStatus = $assignment->driver_status;
            $status = $validated['driver_status'];
            $assignment->driver_status = $status;

            // Update corresponding timestamp
            $timestampColumn = "{$status}_at";
            if (in_array($timestampColumn, [
                'assigned_at', 'accepted_at', 'started_at', 
                'delivered_at', 'cancelled_at', 'refund_at', 'exchange_at'
            ])) {
                $assignment->{$timestampColumn} = now();
            }

            // Sync order table status and delivery details
            $order = Order::find($assignment->order_id);
            $oldOrderStatus = $order ? $order->status : null;
            if ($order) {
                if ($status === 'delivered') {
                    $order->status = 'delivered';
                    $order->delivered_by = $assignment->assigned_driver_user_id;
                    $order->delivered_user_name = $assignment->driver_name;
                    $order->delivered_at = now();
                } elseif ($status === 'cancelled') {
                    $order->status = 'cancelled';
                } elseif ($status === 'started' || $status === 'accepted') {
                    $order->status = 'out_for_delivery';
                }

                $order->save();
                $assignment->order_status = $order->status;
            }

            $assignment->save();

            // Log status change
            OrderStatusLog::create([
                'order_id' => $assignment->order_id,
                'user_id' => $assignment->assigned_driver_user_id,
                'user_name' => $assignment->driver_name ?? 'Driver',
                'action' => 'driver_status_updated',
                'old_status' => $oldDriverStatus,
                'new_status' => $status,
                'notes' => "Driver status updated to '{$status}'" . (!empty($validated['notes']) ? ": {$validated['notes']}" : ""),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Driver status updated to '{$status}' successfully.",
                'data' => $assignment->load(['order.items', 'driver'])
            ], 200);

        } catch (Exception $e) {
            Log::error('Update Driver Status Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update driver status: ' . $e->getMessage()
            ], 500);
        }
    }
}
