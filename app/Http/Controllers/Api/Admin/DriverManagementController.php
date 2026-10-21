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
    // public function assignDriver(Request $request, $orderNumber = null): JsonResponse
    // {
    //     try {
    //         $rawJson = json_decode($request->getContent(), true) ?? [];

    //         $orderNumberInput = $request->input('order_number')
    //             ?? ($rawJson['order_number'] ?? null)
    //             ?? $request->input('ordernumber')
    //             ?? ($rawJson['ordernumber'] ?? null)
    //             ?? $request->input('order_id')
    //             ?? ($rawJson['order_id'] ?? null)
    //             ?? $request->input('order')
    //             ?? ($rawJson['order'] ?? null)
    //             ?? $orderNumber;

    //         $driverId = $request->input('assigned_driver_user_id')
    //             ?? ($rawJson['assigned_driver_user_id'] ?? null)
    //             ?? $request->input('driver_id')
    //             ?? ($rawJson['driver_id'] ?? null)
    //             ?? $request->input('driver_user_id')
    //             ?? ($rawJson['driver_user_id'] ?? null)
    //             ?? $request->input('driver')
    //             ?? ($rawJson['driver'] ?? null);

    //         $zone = $request->input('zone') ?? ($rawJson['zone'] ?? null);

    //         if (empty($orderNumberInput)) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'Either order_number or order_id is required.'
    //             ], 422);
    //         }

    //         if (empty($driverId)) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'The assigned_driver_user_id (or driver_id) field is required.'
    //             ], 422);
    //         }

    //         $numOrIdStr = trim((string) $orderNumberInput);
    //         $cleanNum = ltrim($numOrIdStr, '#');

    //         $order = Order::where('order_number', $numOrIdStr)
    //             ->orWhere('order_number', $cleanNum)
    //             ->orWhere('order_number', '#' . $cleanNum)
    //             ->orWhere('order_number', 'SO-' . $cleanNum)
    //             ->orWhere('id', $numOrIdStr)
    //             ->first();

    //         if (!$order) {
    //             // First or create if not present in DB
    //             $order = Order::create([
    //                 'order_number' => $numOrIdStr,
    //                 'customer_name' => 'Guest Customer',
    //                 'total_amount' => 0.00,
    //                 'status' => 'assigned_to_driver',
    //             ]);
    //         }

    //         $driver = User::find($driverId);
    //         if (!$driver && is_string($driverId)) {
    //             $driver = User::where('name', $driverId)->first();
    //         }

    //         if (!$driver) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => "Driver with ID/name '{$driverId}' not found."
    //             ], 404);
    //         }

    //         $oldStatus = $order->status;
    //         $newStatus = 'assigned_to_driver';

    //         $assignment = OrderDriverAssigned::updateOrCreate(
    //             ['order_id' => $order->id],
    //             [
    //                 'order_number' => $order->order_number,
    //                 'assigned_driver_user_id' => $driver->id,
    //                 'driver_name' => $driver->name,
    //                 'zone' => $zone,
    //                 'order_status' => $newStatus,
    //                 'driver_status' => 'assigned',
    //                 'assigned_at' => now(),
    //             ]
    //         );

    //         $order->update([
    //             'status' => $newStatus,
    //             'delivered_by' => $driver->id,
    //             'delivered_user_name' => $driver->name,
    //         ]);

    //         OrderStatusLog::create([
    //             'order_id' => $order->id,
    //             'user_id' => auth()->id() ?? $driver->id,
    //             'user_name' => auth()->user()?->name ?? 'System',
    //             'action' => 'driver_assigned',
    //             'old_status' => $oldStatus,
    //             'new_status' => $newStatus,
    //             'notes' => "Assigned to driver: {$driver->name} (User ID: {$driver->id})" . (!empty($zone) ? " [Zone: {$zone}]" : ""),
    //         ]);

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Driver assigned successfully.',
    //             'data' => $this->formatAssignmentData($assignment->load(['order.items', 'driver']))
    //         ], 200);

    //     } catch (Exception $e) {
    //         Log::error('Assign Driver Error: ' . $e->getMessage());
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Failed to assign driver: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function assignDriver(Request $request, $orderNumber = null): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            $orderNumberInput = $request->input('order_number')
                ?? $rawJson['order_number']
                ?? $request->input('order_id')
                ?? $rawJson['order_id']
                ?? $request->input('ordernumber')
                ?? $rawJson['ordernumber']
                ?? $request->input('order')
                ?? $rawJson['order']
                ?? $orderNumber;

            $driverId = $request->input('assigned_driver_user_id')
                ?? $rawJson['assigned_driver_user_id']
                ?? $request->input('driver_id')
                ?? $rawJson['driver_id']
                ?? $request->input('driver_user_id')
                ?? $rawJson['driver_user_id']
                ?? $request->input('driver')
                ?? $rawJson['driver']
                ?? null;

            $zone = $request->input('zone') ?? ($rawJson['zone'] ?? null);

            /*
            * A. Validation errors: HTTP 422
            */
            $errors = [];

            if ($orderNumberInput === null || trim((string) $orderNumberInput) === '') {
                $errors['order_id'] = [
                    'The order id or order number field is required.',
                ];
            }

            if ($driverId === null || trim((string) $driverId) === '') {
                $errors['assigned_driver_user_id'] = [
                    'The assigned driver user id field is required.',
                ];
            }

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => reset($errors)[0],
                    'errors' => $errors,
                ], 422);
            }

            /*
            * Find the order by order number or database ID.
            */
            $input = trim((string) $orderNumberInput);
            $cleanNumber = ltrim($input, '#');

            $order = Order::where(function ($query) use ($input, $cleanNumber) {
                $query->where('order_number', $input)
                    ->orWhere('order_number', $cleanNumber)
                    ->orWhere('order_number', '#' . $cleanNumber)
                    ->orWhere('order_number', 'SO-' . $cleanNumber);

                if (ctype_digit($input)) {
                    $query->orWhere('id', (int) $input);
                }
            })->first();

            /*
            * B. Order or driver not found: HTTP 404
            */
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$input} or driver was not found.",
                ], 404);
            }

            $driver = User::find($driverId);

            if (!$driver && is_string($driverId)) {
                $driver = User::where('name', $driverId)->first();
            }

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} or driver was not found.",
                ], 404);
            }

            /*
            * C. Business logic: reject cancelled or delivered orders.
            */
            $currentStatus = strtolower(trim((string) $order->status));

            $blockedStatuses = [
                'cancelled',
                'canceled',
                'delivered',
            ];

            if (in_array($currentStatus, $blockedStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} cannot be assigned to a driver in its current status.",
                ], 400);
            }

            /*
            * Load items and determine which items are VS/installation/normal.
            */
            $order->load(['items.installation']);
            $items = $order->items;

            if ($items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} cannot be assigned because it has no items.",
                ], 400);
            }

            $truthy = static function ($value): bool {
                return in_array(
                    strtolower(trim((string) $value)),
                    ['1', 'true', 'yes'],
                    true
                );
            };

            $isInstallationItem = function ($item) use ($truthy): bool {
                $attributes = $item->getAttributes();

                if (
                    $truthy($attributes['is_installable'] ?? false) ||
                    $truthy($attributes['is_installation'] ?? false) ||
                    $truthy($attributes['installation_required'] ?? false)
                ) {
                    return true;
                }

                if ($item->relationLoaded('installation') && $item->installation) {
                    return true;
                }

                $searchFields = [
                    'product_name',
                    'product_code',
                    'barcode',
                    'vendor',
                    'vendor_name',
                    'product_type',
                    'item_type',
                    'product_tags',
                    'tags',
                    'category',
                    'type',
                ];

                $text = '';
                foreach ($searchFields as $field) {
                    if (isset($attributes[$field])) {
                        $text .= ' ' . strtolower((string) $attributes[$field]);
                    }
                }

                return (bool) preg_match('/\binstallation\b|\binstallations\b/', $text);
            };

            $isVirtualStockItem = function ($item) use ($truthy): bool {
                $attributes = $item->getAttributes();

                if ($truthy($attributes['is_virtual_stock'] ?? false)) {
                    return true;
                }

                $searchFields = [
                    'product_name',
                    'product_code',
                    'barcode',
                    'vendor',
                    'vendor_name',
                    'product_type',
                    'item_type',
                    'product_tags',
                    'tags',
                    'category',
                    'type',
                ];

                $text = '';
                foreach ($searchFields as $field) {
                    if (isset($attributes[$field])) {
                        $text .= ' ' . strtolower((string) $attributes[$field]);
                    }
                }

                return (bool) preg_match('/\bvirtual[\s_-]*stock\b|\bvs\b/', $text);
            };

            // Categorize items
            $installationItems = $items->filter(fn ($item) => $isInstallationItem($item));
            $deliveryItems = $items->reject(fn ($item) => $isInstallationItem($item)); // Normal items + VS items
            $regularItems = $deliveryItems->reject(fn ($item) => $isVirtualStockItem($item)); // Physical warehouse items

            /*
            * All regular items must have status "picked".
            */
            $unpickedItems = $regularItems->filter(function ($item) {
                return strtolower(trim((string) $item->status)) !== 'picked';
            });

            if ($unpickedItems->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} cannot be assigned to a driver in its current status. All regular items must be picked first.",
                    'errors' => [
                        'items' => [
                            'All regular items must be picked before assigning the driver.',
                        ],
                    ],
                ], 400);
            }

            /*
            * Rule 1 & Rule 3:
            * - Orders containing installation items: if all regular items are picked, set order status to partial_assigned_driver.
            * - Normal items + VS items (no installation items): set order status to assigned_to_driver.
            */
            $oldStatus = $order->status;
            $hasInstallation = $installationItems->isNotEmpty();

            $newStatus = $hasInstallation
                ? 'partial_assigned_driver'
                : 'assigned_to_driver';

            /*
            * Rule 1: Normal items + VS (Virtual Stock) items: assign delivered_by and delivered_user_name in order_items.
            */
            foreach ($deliveryItems as $item) {
                $item->update([
                    'delivered_by' => $driver->id,
                    'delivered_user_name' => $driver->name,
                ]);
            }

            /*
            * Rule 2: Installation items: do not assign delivered_by or delivered_user_name to those items, because the installation team handles them.
            */
            foreach ($installationItems as $item) {
                if ($item->delivered_by !== null || $item->delivered_user_name !== null) {
                    $item->update([
                        'delivered_by' => null,
                        'delivered_user_name' => null,
                    ]);
                }
            }

            /*
            * Rule 4: Save the assigned driver details in the orders_driver_assigned table and the order-level orders table as before.
            */
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

            /*
            * Log the status change.
            */
            OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id() ?? $driver->id,
                'user_name' => auth()->user()?->name ?? 'System',
                'action' => 'driver_assigned',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => "Assigned to driver: {$driver->name} (User ID: {$driver->id})"
                    . ($zone ? " [Zone: {$zone}]" : '')
                    . ($hasInstallation ? ' [Installation items remain unassigned to driver]' : ''),
            ]);

            /*
            * Build the response without formatAssignmentData().
            */
            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Driver assigned successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'assigned_driver_user_id' => (int) $driver->id,
                    'driver_name' => $driver->name,
                    'order_status' => $newStatus,
                    'driver_status' => 'Assigned',
                    'zone' => $zone,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Assign Driver Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            /*
            * D. Unexpected server error: HTTP 500
            */
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to assign driver due to an unexpected server error.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
