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

class DeliveryManagementController extends Controller
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
     * Assign driver to an order.
     * Route: POST /api/orders/driver/assign
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

            $order = $this->findOrder($validated);
            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found.'
                ], 404);
            }

            $driver = User::find($validated['assigned_driver_user_id']);
            $oldStatus = $order->status;
            $newStatus = 'assigned_to_driver';

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

            $order->update([
                'status' => $newStatus,
            ]);

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
     * Driver accepts an order.
     * Route: POST /api/orders/driver/accepted
     */
    public function orderAccepted(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'accepted', 'driver_order_accepted');
    }

    /**
     * Driver unaccepts an order assignment.
     * Route: POST /api/orders/driver/unaccepted
     */
    public function orderUnaccepted(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'assigned', 'driver_order_unaccepted');
    }

    /**
     * General status update endpoint for driver.
     * Route: POST /api/orders/driver/update-status
     */
    public function updateDriverStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_status' => 'required|in:assigned,accepted,started,delivered,cancelled,refund,exchange',
        ]);

        return $this->processDriverStatusUpdate($request, $validated['driver_status'], 'driver_status_updated');
    }

    /**
     * Mark order as delivered.
     * Route: POST /api/orders/driver/delivered
     */
    public function orderDelivered(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'delivered', 'driver_order_delivered');
    }

    /**
     * Mark order as cancelled by driver.
     * Route: POST /api/orders/driver/cancelled
     */
    public function orderCancelled(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'cancelled', 'driver_order_cancelled');
    }

    /**
     * Mark order for refund by driver.
     * Route: POST /api/orders/driver/refund
     */
    public function orderRefund(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'refund', 'driver_order_refund');
    }

    /**
     * Mark order for exchange by driver.
     * Route: POST /api/orders/driver/exchange
     */
    public function orderExchange(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'exchange', 'driver_order_exchange');
    }

    /**
     * Get assigned orders for driver (Flutter App API using driver user id).
     * Route: GET /api/orders/driver/assigned/{driver_user_id}
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
     * Helper to process status updates consistently.
     */
    protected function processDriverStatusUpdate(Request $request, string $targetDriverStatus, string $actionName): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_number' => 'nullable|string',
                'order_id' => 'nullable|integer',
                'assignment_id' => 'nullable|integer',
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
                    'message' => 'Driver assignment record not found for the specified order.'
                ], 404);
            }

            $oldDriverStatus = $assignment->driver_status;
            $assignment->driver_status = $targetDriverStatus;

            // Set corresponding timestamp
            $timestampColumn = "{$targetDriverStatus}_at";
            if (in_array($timestampColumn, [
                'assigned_at', 'accepted_at', 'started_at', 
                'delivered_at', 'cancelled_at', 'refund_at', 'exchange_at'
            ])) {
                $assignment->{$timestampColumn} = now();
            }

            // Sync main order status & delivery fields
            $order = Order::find($assignment->order_id);
            $oldOrderStatus = $order ? $order->status : null;
            if ($order) {
                if ($targetDriverStatus === 'delivered') {
                    $order->status = 'delivered';
                    $order->delivered_by = $assignment->assigned_driver_user_id;
                    $order->delivered_user_name = $assignment->driver_name;
                    $order->delivered_at = now();
                } elseif ($targetDriverStatus === 'cancelled') {
                    $order->status = 'cancelled';
                } elseif ($targetDriverStatus === 'refund') {
                    $order->status = 'refund';
                } elseif ($targetDriverStatus === 'exchange') {
                    $order->status = 'exchange';
                } elseif ($targetDriverStatus === 'started' || $targetDriverStatus === 'accepted') {
                    $order->status = 'out_for_delivery';
                } elseif ($targetDriverStatus === 'assigned') {
                    $order->status = 'assigned_to_driver';
                }

                $order->save();
                $assignment->order_status = $order->status;
            }

            $assignment->save();

            // Log status change audit
            OrderStatusLog::create([
                'order_id' => $assignment->order_id,
                'user_id' => auth()->id() ?? $assignment->assigned_driver_user_id,
                'user_name' => auth()->user()?->name ?? ($assignment->driver_name ?? 'Driver'),
                'action' => $actionName,
                'old_status' => $oldDriverStatus,
                'new_status' => $targetDriverStatus,
                'notes' => "Driver status updated to '{$targetDriverStatus}'" . (!empty($validated['notes']) ? ": {$validated['notes']}" : ""),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Driver status updated to '{$targetDriverStatus}' successfully.",
                'data' => $assignment->load(['order.items', 'driver'])
            ], 200);

        } catch (Exception $e) {
            Log::error("Process Driver Status Update Error ({$targetDriverStatus}): " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update driver status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to find order by ID or order_number.
     */
    protected function findOrder(array $validated): ?Order
    {
        $orderQuery = Order::query();
        if (!empty($validated['order_id'])) {
            $orderQuery->where('id', $validated['order_id']);
        } else {
            $orderQuery->where('order_number', $validated['order_number']);
        }
        return $orderQuery->first();
    }
    public function assignedDriver(Request $request): JsonResponse
    {
        try {
            $query = OrderDriverAssigned::with(['order.items', 'driver']);

            // Optional driver filter from request body or query parameter
            $driverId = $request->input('assigned_driver_user_id')
                ?? $request->input('driver_user_id')
                ?? $request->input('driver_id')
                ?? $request->query('driver_user_id')
                ?? $request->query('assigned_driver_user_id');

            if (!empty($driverId)) {
                $query->where('assigned_driver_user_id', $driverId);
            }

            // Optional driver status filter (assigned, accepted, started, delivered, cancelled, refund, exchange)
            $driverStatus = $request->input('driver_status') ?? $request->query('driver_status');
            if (!empty($driverStatus)) {
                $query->where('driver_status', $driverStatus);
            }

            // Optional order status filter
            $orderStatus = $request->input('order_status') ?? $request->query('order_status');
            if (!empty($orderStatus)) {
                $query->where('order_status', $orderStatus);
            }

            // Optional search filter
            $search = $request->input('search') ?? $request->query('search');
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('driver_name', 'like', "%{$search}%")
                      ->orWhere('zone', 'like', "%{$search}%");
                });
            }

            $assignments = $query->orderBy('updated_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Assigned driver orders retrieved successfully.',
                'count' => $assignments->count(),
                'data' => $assignments
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Assigned Driver List Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve assigned driver orders: ' . $e->getMessage()
            ], 500);
        }
    }
}
