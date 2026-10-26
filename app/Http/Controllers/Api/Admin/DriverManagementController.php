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
    
            // 1. Validate required fields.
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
    
            // 2. Find the order by order number or database ID.
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
    
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$input} was not found.",
                ], 404);
            }
    
            // 3. Find the driver.
            $driver = User::find($driverId);
    
            if (!$driver && is_string($driverId)) {
                $driver = User::where('name', $driverId)->first();
            }
    
            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'The assigned driver was not found.',
                ], 404);
            }
    
            // 4. Reject cancelled or delivered orders.
            $currentStatus = strtolower(trim((string) $order->status));
    
            if (in_array($currentStatus, ['cancelled', 'canceled', 'delivered'], true)) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} cannot be assigned to a driver in its current status.",
                ], 400);
            }
    
            // 5. The order must be Ready to Assign.
            if (!in_array($currentStatus, ['ready_assign', 'ready_to_assign'], true)) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} is not ready to assign.",
                    'errors' => [
                        'order_status' => [
                            'The order status must be ready_assign or ready_to_assign before assigning a driver.',
                        ],
                    ],
                    'current_order_status' => $order->status,
                ], 400);
            }
    
            // 6. Load order items and installation relation.
            $order->load(['items.installation']);
            $items = $order->items;
    
            if ($items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} cannot be assigned because it has no items.",
                ], 400);
            }
    
            // 7. Helper to interpret boolean-like values.
            $truthy = static function ($value): bool {
                return in_array(
                    strtolower(trim((string) $value)),
                    ['1', 'true', 'yes'],
                    true
                );
            };
    
            // 8. Identify installation items.
            $isInstallationItem = function ($item) use ($truthy): bool {
                $attributes = $item->getAttributes();
    
                if (
                    $truthy($attributes['is_installable'] ?? false) ||
                    $truthy($attributes['is_installation'] ?? false) ||
                    $truthy($attributes['installation_required'] ?? false)
                ) {
                    return true;
                }
    
                if (
                    $item->relationLoaded('installation') &&
                    $item->installation
                ) {
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
    
                return (bool) preg_match(
                    '/\binstallation\b|\binstallations\b/',
                    $text
                );
            };
    
            // 9. Identify Virtual Stock (VS) items.
            $isVirtualStockItem = function ($item) use ($truthy): bool {
                $attributes = $item->getAttributes();
    
                if ($truthy($attributes['is_virtual_stock'] ?? false)) {
                    return true;
                }
    
                foreach (['product_tags', 'tags'] as $field) {
                    if (!isset($attributes[$field])) {
                        continue;
                    }
    
                    $tags = $attributes[$field];
    
                    if (is_string($tags)) {
                        $decoded = json_decode($tags, true);
    
                        if (is_array($decoded)) {
                            $tags = $decoded;
                        } else {
                            $tags = explode(',', $tags);
                        }
                    }
    
                    if (is_array($tags)) {
                        foreach ($tags as $tag) {
                            if (
                                is_string($tag) &&
                                strcasecmp(trim($tag), 'VS') === 0
                            ) {
                                return true;
                            }
                        }
                    }
                }
    
                // Fallback for existing records.
                $searchFields = [
                    'product_name',
                    'product_code',
                    'barcode',
                    'vendor',
                    'vendor_name',
                    'product_type',
                    'item_type',
                    'category',
                    'type',
                ];
    
                $text = '';
    
                foreach ($searchFields as $field) {
                    if (isset($attributes[$field])) {
                        $text .= ' ' . strtolower((string) $attributes[$field]);
                    }
                }
    
                return (bool) preg_match(
                    '/\bvirtual[\s_-]*stock\b/',
                    $text
                );
            };
    
            // 10. Categorize order items.
            $installationItems = $items->filter(
                fn ($item) => $isInstallationItem($item)
            );
    
            // Regular and VS items are assigned to the delivery driver.
            // Installation items are excluded.
            $deliveryItems = $items->reject(
                fn ($item) => $isInstallationItem($item)
            );
    
            // Regular physical items exclude both VS and installation items.
            $regularItems = $deliveryItems->reject(
                fn ($item) => $isVirtualStockItem($item)
            );
    
            // 11. Validate REGULAR items only.
            // Regular items can be picked OR packed.
            // VS and installation items are exempt from this validation,
            // regardless of their current status.
            $invalidRegularItems = $regularItems->filter(function ($item) {
                $itemStatus = strtolower(trim((string) $item->status));
    
                return !in_array($itemStatus, ['picked', 'packed'], true);
            });
    
            if ($invalidRegularItems->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order {$order->order_number} cannot be assigned. Regular items must be picked or packed.",
                    'errors' => [
                        'items' => [
                            'All regular items must have status picked or packed before assigning the driver.',
                        ],
                    ],
                    'invalid_items' => $invalidRegularItems->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_code' => $item->product_code ?? null,
                            'status' => $item->status,
                        ];
                    })->values(),
                ], 400);
            }
    
            // 12. Determine the new order status.
            $oldStatus = $order->status;
            $hasInstallation = $installationItems->isNotEmpty();
    
            $newStatus = $hasInstallation
                ? 'partial_assigned_driver'
                : 'assigned_to_driver';
    
            // 13. Assign regular and VS items to the driver.
            // Installation items are deliberately excluded.
            foreach ($deliveryItems as $item) {
                $item->update([
                    'delivered_by' => $driver->id,
                    'delivered_user_name' => $driver->name,
                ]);
            }
    
            // 14. Keep installation items unassigned to the delivery driver.
            foreach ($installationItems as $item) {
                if (
                    $item->delivered_by !== null ||
                    $item->delivered_user_name !== null
                ) {
                    $item->update([
                        'delivered_by' => null,
                        'delivered_user_name' => null,
                    ]);
                }
            }
    
            // 15. Create or update the driver assignment.
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
    
            // 16. Update order-level driver details.
            $order->update([
                'status' => $newStatus,
                'delivered_by' => $driver->id,
                'delivered_user_name' => $driver->name,
            ]);
    
            // 17. Log the status change.
            OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id() ?? $driver->id,
                'user_name' => auth()->user()?->name ?? 'System',
                'action' => 'driver_assigned',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => "Assigned to driver: {$driver->name} (User ID: {$driver->id})"
                    . ($zone ? " [Zone: {$zone}]" : '')
                    . ($hasInstallation
                        ? ' [Installation items remain unassigned to driver]'
                        : ''),
            ]);
    
            // 18. Return success response.
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
    
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to assign driver due to an unexpected server error.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
