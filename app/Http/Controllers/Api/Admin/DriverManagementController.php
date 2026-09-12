<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderDriverAssigned;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
     * Assign driver to an order.
     * Route: POST /api/orders/driver/assign
     * Route: POST /api/admin/orders/assign-driver
     * Route: POST /api/admin/orders/{orderNumber}/assign-driver
     */
    public function assignDriver(Request $request, $orderNumber = null): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            $orderNumberInput = $request->input('order_number')
                ?? ($rawJson['order_number'] ?? null)
                ?? $request->input('ordernumber')
                ?? ($rawJson['ordernumber'] ?? null)
                ?? $request->input('order_id')
                ?? ($rawJson['order_id'] ?? null)
                ?? $request->input('order')
                ?? ($rawJson['order'] ?? null)
                ?? $orderNumber;

            $driverId = $request->input('assigned_driver_user_id')
                ?? ($rawJson['assigned_driver_user_id'] ?? null)
                ?? $request->input('driver_id')
                ?? ($rawJson['driver_id'] ?? null)
                ?? $request->input('driver_user_id')
                ?? ($rawJson['driver_user_id'] ?? null)
                ?? $request->input('driver')
                ?? ($rawJson['driver'] ?? null);

            $zone = $request->input('zone') ?? ($rawJson['zone'] ?? null);

            if (empty($orderNumberInput)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Either order_number or order_id is required.'
                ], 422);
            }

            if (empty($driverId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The assigned_driver_user_id (or driver_id) field is required.'
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
                // First or create if not present in DB
                $order = Order::create([
                    'order_number' => $numOrIdStr,
                    'customer_name' => 'Guest Customer',
                    'total_amount' => 0.00,
                    'status' => 'assigned_to_driver',
                ]);
            }

            $driver = User::find($driverId);
            if (!$driver && is_string($driverId)) {
                $driver = User::where('name', $driverId)->first();
            }

            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Driver with ID/name '{$driverId}' not found."
                ], 404);
            }

            $oldStatus = $order->status;
            $newStatus = 'assigned_to_driver';

            $assignment = OrderDriverAssigned::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'order_number' => $order->order_number,
                    'assigned_driver_user_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'zone' => $zone,
                    'order_status' => $newStatus,
                    'driver_status' => 'assigned',
                    'assigned_at' => now(),
                ]
            );

            $order->update([
                'status' => $newStatus,
                'delivered_by' => $driver->id,
                'delivered_user_name' => $driver->name,
            ]);

            OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id() ?? $driver->id,
                'user_name' => auth()->user()?->name ?? 'System',
                'action' => 'driver_assigned',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => "Assigned to driver: {$driver->name} (User ID: {$driver->id})" . (!empty($zone) ? " [Zone: {$zone}]" : ""),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Driver assigned successfully.',
                'data' => $this->formatAssignmentData($assignment->load(['order.items', 'driver']))
            ], 200);

        } catch (Exception $e) {
            Log::error('Assign Driver Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign driver: ' . $e->getMessage()
            ], 500);
        }
    }


}
