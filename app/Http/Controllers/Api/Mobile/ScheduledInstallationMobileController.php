<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\InstallationLevelController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Exception;

class ScheduledInstallationMobileController extends Controller
{
    /**
     * Retrieve and display a list of all installation orders assigned to a particular installer using installer_userid.
     *
     * Route: POST /api/mobile/scheduled/get/{installer_userid}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getScheduledInstallationByInstallerUserId(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID from route param, query param, body, or Auth
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or request body.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details (from users table or fallback constant technicians)
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Optional filters
            $statusFilter = $request->input('status') ?? $request->query('status');
            $search = trim((string) ($request->input('search') ?? $request->query('search', '')));

            // 4. Query orders assigned to this installer
            $ordersQuery = Order::with([
                'items.installation',
                'assignedUser',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned
            $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                // Direct assignment on line items (item-wise installer priority)
                $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                    $iq->where('is_installable', true);
                    $iq->where(function ($sub) use ($idStr, $installerName) {
                        $sub->where('assigned_to', $idStr);
                        if (!empty($installerName)) {
                            $sub->orWhere('assigned_user_name', $installerName);
                        }
                    });
                })
                    // Direct assignment on the order
                    ->orWhere('assigned_to', $idStr)
                    // Or logged in order status audit logs
                    ->orWhereHas('logs', function ($lq) use ($idStr) {
                        $lq->where('user_id', $idStr)
                            ->whereIn('action', [
                                'scheduled_installation',
                                'installation_assigned',
                                'scheduled_installation_mobile',
                                'update_installation_status'
                            ]);
                    });

                if (!empty($installerName)) {
                    $q->orWhere('assigned_user_name', $installerName);
                }
            });

            // Optional status filter
            if (!empty($statusFilter)) {
                $ordersQuery->where(function ($q) use ($statusFilter) {
                    $q->where('status', $statusFilter)
                        ->orWhereHas('items', function ($iq) use ($statusFilter) {
                            $iq->where('is_installable', true)
                                ->where('status', $statusFilter);
                        });
                });
            }

            // Optional keyword search (order number, customer name, phone, product name)
            if (!empty($search)) {
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            // 5. Format results into structured mobile payload
            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, $request) {
                $installableItems = $order->items->filter(function ($item) {
                    return (bool) ($item->is_installable ?? false);
                });

                $items = $installableItems->map(function ($item) use ($idStr, $installerName, $order) {
                    $installation = $item->installation;

                    // Resolve installer ID and name item-wise
                    $itemAssignedTo = $item->assigned_to ? (string) $item->assigned_to : null;
                    $itemInstallerId = $itemAssignedTo
                        ? (is_numeric($itemAssignedTo) ? (int) $itemAssignedTo : $itemAssignedTo)
                        : ($order->assigned_to ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to) : (is_numeric($idStr) ? (int) $idStr : $idStr));

                    $itemInstallerName = $item->assigned_user_name
                        ?: ($item->assignedUser?->name
                            ?: ($order->assigned_user_name ?? $installerName));

                    if (!$itemInstallerName && $itemInstallerId && class_exists(InstallationLevelController::class)) {
                        $tech = collect(InstallationLevelController::TECHNICIANS)
                            ->first(function ($t) use ($itemInstallerId) {
                                return (string) ($t['id'] ?? '') === (string) $itemInstallerId
                                    || (string) ($t['technician_id'] ?? '') === (string) $itemInstallerId;
                            });
                        if ($tech) {
                            $itemInstallerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                        }
                    }

                    if (!$itemInstallerName && $itemInstallerId) {
                        $itemInstallerName = 'Installer #' . $itemInstallerId;
                    }

                    $isAssignedToThisInstaller = ((string) $item->assigned_to === $idStr)
                        || (!empty($installerName) && $item->assigned_user_name === $installerName)
                        || (empty($item->assigned_to) && ((string) $order->assigned_to === $idStr || (!empty($installerName) && $order->assigned_user_name === $installerName)));

                    return [
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'image' => $item->image,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => $item->status,
                        'is_installable' => true,
                        'is_assigned_to_me' => $isAssignedToThisInstaller,
                        'assigned_installer_id' => $itemInstallerId,
                        'assigned_installer_name' => $itemInstallerName,
                        'installer_id' => $itemInstallerId,
                        'installer_name' => $itemInstallerName,
                        'installer_user_id' => $itemInstallerId,
                        'installer' => [
                            'id' => $itemInstallerId,
                            'name' => $itemInstallerName,
                        ],
                        'assigned_to' => $itemInstallerId,
                        'assigned_user_name' => $itemInstallerName,
                        'assigned_at' => $item->assigned_at?->toIso8601String() ?? $order->assigned_at?->toIso8601String(),
                        'installation' => $installation ? [
                            'id' => $installation->id,
                            'order_item_id' => $installation->order_item_id,
                            'installation_type' => $installation->installation_type,
                            'installation_level' => $installation->installation_level,
                            'status' => $installation->status ?? ($item->status ?? 'scheduled'),
                            'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                        ] : null,
                        'is_scheduled' => in_array($item->status, ['scheduled', 'scheduled_installation', 'installation', 'in_progress', 'started'])
                            || in_array($installation?->status, ['scheduled', 'in_progress', 'started'])
                            || (bool) ($installation?->is_scheduled_assigned ?? false),
                    ];
                })->values();

                // Optional filter by query param: ?only_my_items=1 or ?filter_items=assigned
                $onlyMyItems = filter_var($request->query('only_my_items', false), FILTER_VALIDATE_BOOLEAN)
                    || filter_var($request->query('only_assigned_items', false), FILTER_VALIDATE_BOOLEAN)
                    || strtolower((string) $request->query('filter_items', '')) === 'assigned';

                if ($onlyMyItems) {
                    $items = $items->filter(function ($item) {
                        return (bool) ($item['is_assigned_to_me'] ?? false);
                    })->values();
                }

                $isOrderAssignedToMe = ((string) $order->assigned_to === $idStr)
                    || (!empty($installerName) && $order->assigned_user_name === $installerName)
                    || $items->contains('is_assigned_to_me', true);

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($items->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($items->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                return [
                    'order_number' => $order->order_number,
                    'order_status' => $order->status,
                    'status' => $order->status,
                    'customer_name' => $order->customer_name ?? 'N/A',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'total_amount' => (float) $order->total_amount,
                    'bag_count' => (int) ($order->bag_count ?? 0),
                    'is_assigned_to_me' => $isOrderAssignedToMe,
                    'assigned_installer_id' => $orderInstallerId,
                    'assigned_installer_name' => $orderInstallerName,
                    'assigned_to' => $orderInstallerId,
                    'assigned_user_name' => $orderInstallerName,
                    'assigned_at' => $order->assigned_at?->toIso8601String(),
                    'created_at' => $order->created_at?->toIso8601String(),
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'items_count' => $items->count(),
                    'total_items' => $items->count(),
                    'items' => $items,
                ];
            })->filter(function ($ord) {
                return $ord['items_count'] > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                'installer_name' => $installerName ?? ('Installer #' . $idStr),
                'count' => $formattedOrders->count(),
                'data' => $formattedOrders,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getScheduledInstallationByInstallerUserId: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve scheduled installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve and list all in-progress installation orders and items for a specific installer.
     *
     * Route: GET|POST /api/mobile/scheduled/in-progress/{installer_userid?}
     * Route: GET|POST /api/mobile/scheduled/inprogress/{installer_userid?}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getInProgressInstallationsByInstallerUserId(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID from route param, query param, body, or Auth
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or query.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Query orders with items and installation records
            $ordersQuery = Order::with([
                'items.installation',
                'assignedUser',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned
            $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                    $iq->where('is_installable', true)
                        ->where(function ($sub) use ($idStr, $installerName) {
                            $sub->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $sub->orWhere('assigned_user_name', $installerName);
                            }
                        });
                })
                    ->orWhere('assigned_to', $idStr)
                    ->orWhereHas('logs', function ($lq) use ($idStr) {
                        $lq->where('user_id', $idStr)
                            ->whereIn('action', [
                                'scheduled_installation',
                                'installation_assigned',
                                'scheduled_installation_mobile',
                                'update_installation_status'
                            ]);
                    });

                if (!empty($installerName)) {
                    $q->orWhere('assigned_user_name', $installerName);
                }
            });

            // Filter for in_progress status across order_installations or orders
            $ordersQuery->where(function ($q) {
                $q->whereIn('status', ['in_progress', 'started'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_installable', true)
                            ->where(function ($iiq) {
                                $iiq->whereIn('status', ['in_progress', 'started'])
                                    ->orWhereHas('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['in_progress', 'started']);
                                    });
                            });
                    });
            });

            // Optional keyword search
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            // 4. Build both grouped orders and a flattened item list
            $flattenedItems = [];

            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, &$flattenedItems) {
                // Filter items to in-progress items assigned to this installer (installable only)
                $inProgressItems = $order->items->filter(function ($item) use ($idStr, $installerName, $order) {
                    if (!($item->is_installable ?? false)) {
                        return false;
                    }

                    $installation = $item->installation;
                    $isItemInProgress = ($order->status === 'in_progress')
                        || ($item->status === 'in_progress' || $item->status === 'started')
                        || ($installation && in_array($installation->status, ['in_progress', 'started']));

                    $isAssignedToThisInstaller = ((string) $item->assigned_to === $idStr)
                        || (!empty($installerName) && $item->assigned_user_name === $installerName)
                        || (empty($item->assigned_to) && ((string) $order->assigned_to === $idStr || (!empty($installerName) && $order->assigned_user_name === $installerName)));

                    return $isItemInProgress && $isAssignedToThisInstaller;
                })->values();

                // If no items directly matched in_progress filter, fallback to all installer items for this in-progress order
                if ($inProgressItems->isEmpty() && in_array($order->status, ['in_progress', 'started'])) {
                    $inProgressItems = $order->items->filter(function ($item) use ($idStr, $installerName, $order) {
                        return (bool) ($item->is_installable ?? false)
                            && (((string) $item->assigned_to === $idStr)
                                || (!empty($installerName) && $item->assigned_user_name === $installerName)
                                || ((string) $order->assigned_to === $idStr));
                    })->values();
                }

                $mappedItems = $inProgressItems->map(function ($item) use ($idStr, $installerName, $order, &$flattenedItems) {
                    $installation = $item->installation;

                    $itemAssignedTo = $item->assigned_to ? (string) $item->assigned_to : null;
                    $itemInstallerId = $itemAssignedTo
                        ? (is_numeric($itemAssignedTo) ? (int) $itemAssignedTo : $itemAssignedTo)
                        : ($order->assigned_to ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to) : (is_numeric($idStr) ? (int) $idStr : $idStr));

                    $itemInstallerName = $item->assigned_user_name
                        ?: ($item->assignedUser?->name
                            ?: ($order->assigned_user_name ?? $installerName));

                    if (!$itemInstallerName && $itemInstallerId && class_exists(InstallationLevelController::class)) {
                        $tech = collect(InstallationLevelController::TECHNICIANS)
                            ->first(function ($t) use ($itemInstallerId) {
                                return (string) ($t['id'] ?? '') === (string) $itemInstallerId
                                    || (string) ($t['technician_id'] ?? '') === (string) $itemInstallerId;
                            });
                        if ($tech) {
                            $itemInstallerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                        }
                    }

                    if (!$itemInstallerName && $itemInstallerId) {
                        $itemInstallerName = 'Installer #' . $itemInstallerId;
                    }

                    $itemData = [
                        'order_number' => $order->order_number,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'image' => $item->image,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => 'in_progress',
                        'order_status' => $order->status,
                        'is_installable' => true,
                        'is_assigned_to_me' => true,
                        'assigned_installer_id' => $itemInstallerId,
                        'assigned_installer_name' => $itemInstallerName,
                        'installer_id' => $itemInstallerId,
                        'installer_name' => $itemInstallerName,
                        'installer_user_id' => $itemInstallerId,
                        'installer' => [
                            'id' => $itemInstallerId,
                            'name' => $itemInstallerName,
                        ],
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'installation' => [
                            'id' => $installation?->id,
                            'order_item_id' => $installation?->order_item_id ?? $item->id,
                            'installation_type' => $installation?->installation_type,
                            'installation_level' => $installation?->installation_level,
                            'status' => 'in_progress',
                            'is_scheduled_assigned' => (bool) ($installation?->is_scheduled_assigned ?? true),
                        ],
                        'updated_at' => $installation?->updated_at?->toIso8601String() ?? $order->updated_at?->toIso8601String(),
                    ];

                    $flattenedItems[] = $itemData;

                    return $itemData;
                })->values();

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($mappedItems->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($mappedItems->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                return [
                    'order_number' => $order->order_number,
                    'order_status' => 'in_progress',
                    'status' => 'in_progress',
                    'customer_name' => $order->customer_name ?? 'N/A',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'total_amount' => (float) $order->total_amount,
                    'bag_count' => (int) ($order->bag_count ?? 0),
                    'is_assigned_to_me' => true,
                    'assigned_installer_id' => $orderInstallerId,
                    'assigned_installer_name' => $orderInstallerName,
                    'installer_id' => $orderInstallerId,
                    'installer_name' => $orderInstallerName,
                    'installer_user_id' => $orderInstallerId,
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'items_count' => $mappedItems->count(),
                    'total_items' => $mappedItems->count(),
                    'items' => $mappedItems,
                ];
            })->filter(function ($ord) {
                return $ord['items_count'] > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                'installer_name' => $installerName ?? ('Installer #' . $idStr),
                'status_filter' => 'in_progress',
                'orders_count' => $formattedOrders->count(),
                'items_count' => count($flattenedItems),
                'data' => $formattedOrders,
                'items_list' => $flattenedItems,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getInProgressInstallationsByInstallerUserId: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve in-progress installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve and list all completed / installed installation orders and items for a specific installer.
     *
     * Route: GET|POST /api/mobile/scheduled/completed/{installer_userid?}
     * Route: GET|POST /api/mobile/scheduled/installed/{installer_userid?}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getCompletedInstallationsByInstallerUserId(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID from route param, query param, body, or Auth
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or query.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Query orders with items and installation records
            $ordersQuery = Order::with([
                'items.installation',
                'assignedUser',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned
            $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                    $iq->where('is_installable', true)
                        ->where(function ($sub) use ($idStr, $installerName) {
                            $sub->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $sub->orWhere('assigned_user_name', $installerName);
                            }
                        });
                })
                    ->orWhere('assigned_to', $idStr)
                    ->orWhereHas('logs', function ($lq) use ($idStr) {
                        $lq->where('user_id', $idStr)
                            ->whereIn('action', [
                                'scheduled_installation',
                                'installation_assigned',
                                'scheduled_installation_mobile',
                                'update_installation_status'
                            ]);
                    });

                if (!empty($installerName)) {
                    $q->orWhere('assigned_user_name', $installerName);
                }
            });

            // Filter for completed or installed status across order_installations, orders, or order items
            $ordersQuery->where(function ($q) {
                $q->whereIn('status', ['completed', 'installed'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_installable', true)
                            ->where(function ($iiq) {
                                $iiq->whereIn('status', ['completed', 'installed'])
                                    ->orWhereHas('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['completed', 'installed']);
                                    });
                            });
                    });
            });

            // Optional keyword search
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            // 4. Build both grouped orders and a flattened item list
            $flattenedItems = [];

            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, &$flattenedItems) {
                // Filter items to completed/installed items assigned to this installer (installable only)
                $completedItems = $order->items->filter(function ($item) use ($idStr, $installerName, $order) {
                    if (!($item->is_installable ?? false)) {
                        return false;
                    }

                    $installation = $item->installation;
                    $isItemCompleted = in_array($order->status, ['completed', 'installed'])
                        || in_array($item->status, ['completed', 'installed'])
                        || ($installation && in_array($installation->status, ['completed', 'installed']));

                    $isAssignedToThisInstaller = ((string) $item->assigned_to === $idStr)
                        || (!empty($installerName) && $item->assigned_user_name === $installerName)
                        || (empty($item->assigned_to) && ((string) $order->assigned_to === $idStr || (!empty($installerName) && $order->assigned_user_name === $installerName)));

                    return $isItemCompleted && $isAssignedToThisInstaller;
                })->values();

                // Fallback for orders marked completed
                if ($completedItems->isEmpty() && in_array($order->status, ['completed', 'installed'])) {
                    $completedItems = $order->items->filter(function ($item) use ($idStr, $installerName, $order) {
                        return (bool) ($item->is_installable ?? false)
                            && (((string) $item->assigned_to === $idStr)
                                || (!empty($installerName) && $item->assigned_user_name === $installerName)
                                || ((string) $order->assigned_to === $idStr));
                    })->values();
                }

                $mappedItems = $completedItems->map(function ($item) use ($idStr, $installerName, $order, &$flattenedItems) {
                    $installation = $item->installation;

                    $itemAssignedTo = $item->assigned_to ? (string) $item->assigned_to : null;
                    $itemInstallerId = $itemAssignedTo
                        ? (is_numeric($itemAssignedTo) ? (int) $itemAssignedTo : $itemAssignedTo)
                        : ($order->assigned_to ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to) : (is_numeric($idStr) ? (int) $idStr : $idStr));

                    $itemInstallerName = $item->assigned_user_name
                        ?: ($item->assignedUser?->name
                            ?: ($order->assigned_user_name ?? $installerName));

                    if (!$itemInstallerName && $itemInstallerId && class_exists(InstallationLevelController::class)) {
                        $tech = collect(InstallationLevelController::TECHNICIANS)
                            ->first(function ($t) use ($itemInstallerId) {
                                return (string) ($t['id'] ?? '') === (string) $itemInstallerId
                                    || (string) ($t['technician_id'] ?? '') === (string) $itemInstallerId;
                            });
                        if ($tech) {
                            $itemInstallerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                        }
                    }

                    if (!$itemInstallerName && $itemInstallerId) {
                        $itemInstallerName = 'Installer #' . $itemInstallerId;
                    }

                    $resolvedStatus = ($installation && in_array($installation->status, ['completed', 'installed']))
                        ? $installation->status
                        : (in_array($item->status, ['completed', 'installed']) ? $item->status : 'completed');

                    $itemData = [
                        'order_number' => $order->order_number,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'image' => $item->image,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => $resolvedStatus,
                        'order_status' => $order->status,
                        'is_installable' => true,
                        'is_assigned_to_me' => true,
                        'assigned_installer_id' => $itemInstallerId,
                        'assigned_installer_name' => $itemInstallerName,
                        'installer_id' => $itemInstallerId,
                        'installer_name' => $itemInstallerName,
                        'installer_user_id' => $itemInstallerId,
                        'installer' => [
                            'id' => $itemInstallerId,
                            'name' => $itemInstallerName,
                        ],
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'installation' => [
                            'id' => $installation?->id,
                            'order_item_id' => $installation?->order_item_id ?? $item->id,
                            'installation_type' => $installation?->installation_type,
                            'installation_level' => $installation?->installation_level,
                            'status' => $resolvedStatus,
                            'is_scheduled_assigned' => (bool) ($installation?->is_scheduled_assigned ?? true),
                        ],
                        'updated_at' => $installation?->updated_at?->toIso8601String() ?? $order->updated_at?->toIso8601String(),
                    ];

                    $flattenedItems[] = $itemData;

                    return $itemData;
                })->values();

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($mappedItems->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($mappedItems->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                return [
                    'order_number' => $order->order_number,
                    'order_status' => $order->status,
                    'status' => $order->status,
                    'customer_name' => $order->customer_name ?? 'N/A',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'total_amount' => (float) $order->total_amount,
                    'bag_count' => (int) ($order->bag_count ?? 0),
                    'is_assigned_to_me' => true,
                    'assigned_installer_id' => $orderInstallerId,
                    'assigned_installer_name' => $orderInstallerName,
                    'installer_id' => $orderInstallerId,
                    'installer_name' => $orderInstallerName,
                    'installer_user_id' => $orderInstallerId,
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'items_count' => $mappedItems->count(),
                    'total_items' => $mappedItems->count(),
                    'items' => $mappedItems,
                ];
            })->filter(function ($ord) {
                return $ord['items_count'] > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                'installer_name' => $installerName ?? ('Installer #' . $idStr),
                'status_filter' => 'completed',
                'orders_count' => $formattedOrders->count(),
                'items_count' => count($flattenedItems),
                'data' => $formattedOrders,
                'items_list' => $flattenedItems,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getCompletedInstallationsByInstallerUserId: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve completed installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin or Mobile assign an order ID to an installer.
     *
     * Route: POST /api/mobile/scheduled/assign
     */
    public function assignInstaller(Request $request): JsonResponse
    {
        $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
        if ($request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $validator = Validator::make($payload, [
            'order_id' => 'nullable',
            'order_number' => 'nullable|string',
            'item_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'item_ids' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'installer_userid' => 'nullable',
            'installer_id' => 'nullable',
            'user_id' => 'nullable',
            'assigned_to' => 'nullable',
            'installer_name' => 'nullable|string',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $installerId = $payload['installer_userid']
            ?? $payload['installer_id']
            ?? $payload['user_id']
            ?? $payload['assigned_to']
            ?? $request->input('installer_userid');

        if (!$installerId) {
            return response()->json([
                'status' => 'error',
                'message' => 'installer_userid is required.',
            ], 422);
        }

        $orderId = $payload['order_id'] ?? $request->input('order_id');
        $orderNumber = $payload['order_number'] ?? $request->input('order_number');
        $itemId = $payload['item_id'] ?? $payload['order_item_id'] ?? $payload['line_item_id'] ?? $request->input('item_id');
        $itemIds = $payload['item_ids'] ?? $payload['order_item_ids'] ?? $payload['line_item_ids'] ?? null;

        if (!$orderId && !$orderNumber && !$itemId && empty($itemIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'order_id, order_number, or item_id/item_ids is required.',
            ], 422);
        }

        try {
            return DB::transaction(function () use ($payload, $orderId, $orderNumber, $itemId, $itemIds, $installerId) {
                $idStr = trim((string) $installerId);
                $installerUser = User::find($idStr);
                $installerName = $payload['installer_name'] ?? $payload['user_name'] ?? $installerUser?->name;

                if (!$installerName && class_exists(InstallationLevelController::class)) {
                    $tech = collect(InstallationLevelController::TECHNICIANS)
                        ->first(function ($t) use ($idStr) {
                            return (string) ($t['id'] ?? '') === $idStr
                                || (string) ($t['technician_id'] ?? '') === $idStr;
                        });
                    if ($tech) {
                        $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                    }
                }

                if (!$installerName) {
                    $installerName = 'Installer #' . $idStr;
                }

                // If specific item(s) are provided for item-wise assignment
                $targetItemIds = [];
                if (!empty($itemIds) && is_array($itemIds)) {
                    $targetItemIds = $itemIds;
                } elseif ($itemId) {
                    $targetItemIds = [$itemId];
                }

                if (!empty($targetItemIds)) {
                    $itemsToUpdate = OrderItem::whereIn('id', $targetItemIds)
                        ->orWhereIn('line_item_id', $targetItemIds)
                        ->get();

                    if ($itemsToUpdate->isEmpty()) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Specified items not found.',
                        ], 404);
                    }

                    $parentOrder = Order::find($itemsToUpdate->first()->order_id);

                    foreach ($itemsToUpdate as $item) {
                        $itemUpdates = [
                            'assigned_to' => $idStr,
                            'assigned_user_name' => $installerName,
                            'assigned_at' => now(),
                        ];
                        if (!in_array($item->status, ['installed', 'completed'])) {
                            $itemUpdates['status'] = 'scheduled';
                        }
                        $item->update($itemUpdates);

                        if (Schema::hasTable('order_installations')) {
                            OrderInstallation::updateOrCreate(
                                ['order_item_id' => $item->id],
                                ['is_scheduled_assigned' => true]
                            );
                        }

                        OrderStatusLog::create([
                            'order_id' => $item->order_id,
                            'order_item_id' => $item->id,
                            'user_id' => $idStr,
                            'user_name' => $installerName,
                            'action' => 'installation_assigned',
                            'old_status' => $item->status,
                            'new_status' => 'scheduled',
                            'notes' => $payload['notes'] ?? "Item {$item->product_name} assigned to installer {$installerName} (ID: {$idStr})",
                        ]);
                    }

                    if ($parentOrder && in_array($parentOrder->status, ['pending', 'new', 'ready_to_assign', 'ready_for_installation', 'installation'])) {
                        $parentOrder->update(['status' => 'scheduled']);
                    }

                    return response()->json([
                        'status' => 'success',
                        'message' => count($itemsToUpdate) . " item(s) successfully assigned to installer {$installerName}.",
                        'data' => [
                            'order_id' => $parentOrder?->id,
                            'order_number' => $parentOrder?->order_number,
                            'assigned_items_count' => $itemsToUpdate->count(),
                            'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                            'installer_name' => $installerName,
                            'assigned_at' => now()->toIso8601String(),
                            'items' => $itemsToUpdate->map(function ($it) use ($idStr, $installerName) {
                                return [
                                    'item_id' => $it->id,
                                    'line_item_id' => $it->line_item_id,
                                    'product_name' => $it->product_name,
                                    'status' => $it->fresh()->status,
                                    'assigned_installer_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                                    'assigned_installer_name' => $installerName,
                                ];
                            })->values(),
                        ],
                    ], 200);
                }

                $cleanNum = $orderNumber ? trim(ltrim($orderNumber, '#')) : null;
                $order = Order::where(function ($q) use ($orderId, $orderNumber, $cleanNum) {
                    if ($orderId) {
                        $q->orWhere('id', $orderId);
                    }
                    if ($orderNumber) {
                        $q->orWhere('order_number', $orderNumber)
                            ->orWhere('order_number', $cleanNum)
                            ->orWhere('order_number', '#' . $cleanNum);
                    }
                })->first();

                if (!$order) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Order not found.',
                    ], 404);
                }

                // Update order assignment
                $orderUpdates = [
                    'assigned_to' => $idStr,
                    'assigned_user_name' => $installerName,
                    'assigned_at' => now(),
                ];
                if (in_array($order->status, ['pending', 'new', 'ready_to_assign', 'ready_for_installation', 'installation'])) {
                    $orderUpdates['status'] = 'scheduled';
                }
                $order->update($orderUpdates);

                // Update all items in this order
                foreach ($order->items as $item) {
                    $oldStatus = $item->status;
                    $itemUpdates = [
                        'assigned_to' => $idStr,
                        'assigned_user_name' => $installerName,
                        'assigned_at' => now(),
                    ];
                    if (!in_array($oldStatus, ['installed', 'completed'])) {
                        $itemUpdates['status'] = 'scheduled';
                    }
                    $item->update($itemUpdates);

                    if (Schema::hasTable('order_installations')) {
                        OrderInstallation::updateOrCreate(
                            ['order_item_id' => $item->id],
                            ['is_scheduled_assigned' => true]
                        );
                    }
                }

                // Log assignment
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $idStr,
                    'user_name' => $installerName,
                    'action' => 'installation_assigned',
                    'old_status' => $order->status,
                    'new_status' => 'scheduled',
                    'notes' => $payload['notes'] ?? "Order #{$order->order_number} assigned to installer {$installerName} (ID: {$idStr})",
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Order #{$order->order_number} successfully assigned to installer {$installerName}.",
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_status' => $order->fresh()->status,
                        'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                        'installer_name' => $installerName,
                        'assigned_at' => now()->toIso8601String(),
                    ],
                ], 200);
            });
        } catch (Exception $e) {
            Log::error('Error assigning installer to order: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign installer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update installation status from mobile (e.g., scheduled, in_progress, installed, completed, cancelled).
     *
     * Route: POST /api/mobile/scheduled/update-status
     */
    public function updateStatus(Request $request, string|int|null $id = null): JsonResponse
    {
        $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
        if ($request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $validator = Validator::make($payload, [
            'status' => 'required|string',
            'order_id' => 'nullable',
            'order_number' => 'nullable|string',
            'item_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'notes' => 'nullable|string',
            'user_name' => 'nullable|string',
            'user_id' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rawStatus = trim((string) $payload['status']);
        $normalizedStatus = strtolower(str_replace(['-', ' '], '_', $rawStatus));
        if ($normalizedStatus === 'started' || $normalizedStatus === 'start') {
            $normalizedStatus = 'in_progress';
        } elseif ($normalizedStatus === 'complete') {
            $normalizedStatus = 'completed';
        } elseif ($normalizedStatus === 'install') {
            $normalizedStatus = 'installed';
        }

        $orderId = $payload['order_id'] ?? $request->input('order_id');
        $orderNumber = $payload['order_number'] ?? $request->input('order_number');
        $itemId = $payload['item_id'] ?? $payload['order_item_id'] ?? $payload['line_item_id'] ?? $request->input('item_id');
        $targetId = $id ?? $itemId ?? $orderId;

        if (!$targetId && !$orderNumber) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item ID, Order ID, or Order Number is required.',
            ], 422);
        }

        try {
            return DB::transaction(function () use ($request, $payload, $targetId, $itemId, $orderId, $orderNumber, $normalizedStatus) {
                $orderItem = null;
                $order = null;

                // 1. If order_id or order_number is explicitly provided, resolve order first
                if ($orderId || $orderNumber) {
                    $cleanNum = $orderNumber ? trim(ltrim($orderNumber, '#')) : null;
                    $order = Order::where(function ($q) use ($orderId, $orderNumber, $cleanNum) {
                        if ($orderId) {
                            $q->orWhere('id', $orderId);
                        }
                        if ($orderNumber) {
                            $q->orWhere('order_number', $orderNumber)
                                ->orWhere('order_number', $cleanNum)
                                ->orWhere('order_number', '#' . $cleanNum);
                        }
                    })->first();
                }

                // 2. If itemId is explicitly provided, resolve item
                if ($itemId) {
                    $orderItem = OrderItem::where('id', $itemId)
                        ->orWhere('line_item_id', (string) $itemId)
                        ->first();
                    if ($orderItem && !$order) {
                        $order = Order::find($orderItem->order_id);
                    }
                }

                // 3. If neither resolved yet and targetId was provided (e.g. from URL /update-status/{id})
                if (!$order && !$orderItem && $targetId) {
                    // Check order first
                    $order = Order::find($targetId);
                    if (!$order) {
                        $orderItem = OrderItem::where('id', $targetId)
                            ->orWhere('line_item_id', (string) $targetId)
                            ->first();
                        if ($orderItem) {
                            $order = Order::find($orderItem->order_id);
                        }
                    }
                }

                if (!$orderItem && !$order) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Target order or order item not found.',
                    ], 404);
                }

                $oldStatus = $orderItem ? $orderItem->status : $order->status;
                $orderInstallation = null;
                $validItemEnums = ['pending', 'picked', 'packed', 'delivered', 'cancelled'];

                // 4. Update single item and its order_installations record
                if ($orderItem) {
                    if (in_array($normalizedStatus, $validItemEnums)) {
                        $orderItem->update(['status' => $normalizedStatus]);
                    }

                    if (Schema::hasTable('order_installations')) {
                        $orderInstallation = OrderInstallation::updateOrCreate(
                            ['order_item_id' => $orderItem->id],
                            [
                                'status' => $normalizedStatus,
                                'is_scheduled_assigned' => true,
                            ]
                        );
                    }

                    if ($order) {
                        if ($normalizedStatus === 'in_progress') {
                            $order->update(['status' => 'in_progress']);
                        } elseif ($normalizedStatus === 'installed' || $normalizedStatus === 'completed') {
                            $remaining = OrderItem::where('order_id', $order->id)
                                ->where(function ($remQ) {
                                    $remQ->whereDoesntHave('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['installed', 'completed']);
                                    })
                                        ->whereNotIn('status', ['installed', 'completed', 'delivered']);
                                })
                                ->count();
                            if ($remaining === 0) {
                                $order->update(['status' => $normalizedStatus]);
                            }
                        } else {
                            $order->update(['status' => $normalizedStatus]);
                        }
                    }
                } elseif ($order) {
                    // 5. Update whole order and all its items' order_installations records
                    $order->update(['status' => $normalizedStatus]);

                    foreach ($order->items as $item) {
                        if (in_array($normalizedStatus, $validItemEnums)) {
                            $item->update(['status' => $normalizedStatus]);
                        }

                        if (Schema::hasTable('order_installations')) {
                            $orderInstallation = OrderInstallation::updateOrCreate(
                                ['order_item_id' => $item->id],
                                [
                                    'status' => $normalizedStatus,
                                    'is_scheduled_assigned' => true,
                                ]
                            );
                        }
                    }
                }

                $userName = $payload['user_name'] ?? $request->input('user_name') ?? (Auth::user()?->name ?? 'Mobile Installer');
                $userId = $payload['user_id'] ?? $request->input('user_id') ?? Auth::id();
                $notes = $payload['notes'] ?? $request->input('notes', "Installation status updated to {$normalizedStatus}");

                OrderStatusLog::create([
                    'order_id' => $order?->id ?? $orderItem?->order_id,
                    'order_item_id' => $orderItem?->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'update_installation_status',
                    'old_status' => $oldStatus,
                    'new_status' => $normalizedStatus,
                    'notes' => $notes,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Installation status updated to {$normalizedStatus}.",
                    'data' => [
                        'order_id' => $order?->id ?? $orderItem?->order_id,
                        'order_number' => $order?->order_number,
                        'order_status' => $order?->fresh()->status,
                        'order_item_id' => $orderItem?->id,
                        'item_status' => $orderItem?->fresh()->status,
                        'installation_status' => $normalizedStatus,
                        'installation' => $orderInstallation ?? null,
                    ],
                ], 200);
            });
        } catch (Exception $e) {
            Log::error('Error updating installation status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update installation status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
