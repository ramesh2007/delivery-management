<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\InstallationLevelController;
use App\Models\InstallationImage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\OrderStatusLog;
use App\Models\TechnicianScheduled;
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
                'technicianSchedules',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned (unless 'all' is requested)
            if ($idStr !== 'all' && !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN)) {
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
                        // Technician schedules
                        ->orWhereHas('technicianSchedules', function ($tq) use ($idStr, $installerName) {
                            $tq->where('technician_id', $idStr);
                            if (!empty($installerName)) {
                                $tq->orWhere('technician_name', $installerName);
                            }
                        })
                        // Or logged in order status audit logs
                        ->orWhereHas('logs', function ($lq) use ($idStr) {
                            $lq->where('user_id', $idStr)
                                ->whereIn('action', [
                                    'scheduled_installation',
                                    'installation_assigned',
                                    'scheduled_installation_mobile',
                                    'update_installation_status',
                                    'technician_scheduled'
                                ]);
                        });

                    if (!empty($installerName)) {
                        $q->orWhere('assigned_user_name', $installerName);
                    }
                });
            }

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
                // Map all items with complete installation and installable status (1 = yes, 0 = no)
                $allFormattedItems = $order->items->map(function ($item) use ($idStr, $installerName, $order) {
                    $installation = $item->installation;
                    $isInstallableBool = (bool) ($item->is_installable ?? false);

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

                    $installationPayload = $installation ? [
                        'id' => $installation->id,
                        'order_item_id' => $installation->order_item_id,
                        'installation_type' => $installation->installation_type,
                        'installation_level' => $installation->installation_level,
                        'status' => $installation->status ?? ($item->status ?? 'scheduled'),
                        'notes' => $installation->notes ?? null,
                        'images' => is_array($installation->images) ? $installation->images : (json_decode($installation->images ?? '[]', true) ?: []),
                        'completed_at' => $installation->completed_at?->toIso8601String(),
                        'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                        'created_at' => $installation->created_at?->toIso8601String(),
                        'updated_at' => $installation->updated_at?->toIso8601String(),
                    ] : null;

                    return [
                        'id' => $item->id,
                        'order_item_id' => $item->id,
                        'order_id' => $item->order_id,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'image' => $item->image,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => $item->status,
                        'is_installable' => $isInstallableBool ? 1 : 0,
                        'is_installable_bool' => $isInstallableBool,
                        'is_installable_label' => $isInstallableBool ? 'yes' : 'no',
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
                        'installation' => $installationPayload,
                        'order_installation' => $installationPayload,
                        'is_scheduled' => in_array($item->status, ['scheduled', 'scheduled_installation', 'installation', 'in_progress', 'started'])
                            || in_array($installation?->status, ['scheduled', 'in_progress', 'started'])
                            || (bool) ($installation?->is_scheduled_assigned ?? false),
                    ];
                })->values();

                // Installation items specifically (is_installable = 1)
                $installationItems = $allFormattedItems->filter(function ($it) {
                    return $it['is_installable'] === 1;
                })->values();

                // Order installations list from order_installations table
                $orderInstallations = $order->items
                    ->map(function ($it) {
                        return $it->installation;
                    })
                    ->filter()
                    ->map(function ($inst) {
                        return [
                            'id' => $inst->id,
                            'order_item_id' => $inst->order_item_id,
                            'installation_type' => $inst->installation_type,
                            'installation_level' => $inst->installation_level,
                            'status' => $inst->status,
                            'notes' => $inst->notes ?? null,
                            'images' => is_array($inst->images) ? $inst->images : (json_decode($inst->images ?? '[]', true) ?: []),
                            'completed_at' => $inst->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) $inst->is_scheduled_assigned,
                            'created_at' => $inst->created_at?->toIso8601String(),
                            'updated_at' => $inst->updated_at?->toIso8601String(),
                        ];
                    })
                    ->values();

                // Optional filter by query param: ?only_my_items=1 or ?filter_items=assigned
                $onlyMyItems = filter_var($request->query('only_my_items', false), FILTER_VALIDATE_BOOLEAN)
                    || filter_var($request->query('only_assigned_items', false), FILTER_VALIDATE_BOOLEAN)
                    || strtolower((string) $request->query('filter_items', '')) === 'assigned';

                $displayItems = $allFormattedItems;
                if ($onlyMyItems) {
                    $displayItems = $displayItems->filter(function ($item) {
                        return (bool) ($item['is_assigned_to_me'] ?? false);
                    })->values();
                }

                $isOrderAssignedToMe = ((string) $order->assigned_to === $idStr)
                    || (!empty($installerName) && $order->assigned_user_name === $installerName)
                    || $allFormattedItems->contains('is_assigned_to_me', true);

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($allFormattedItems->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($allFormattedItems->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                return [
                    'id' => $order->id,
                    'order_id' => $order->id,
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
                    'total_items_count' => $allFormattedItems->count(),
                    'items_count' => $displayItems->count(),
                    'total_items' => $allFormattedItems->count(),
                    'installation_items_count' => $installationItems->count(),
                    'installations_count' => $orderInstallations->count(),
                    'items' => $displayItems,
                    'order_items' => $allFormattedItems,
                    'installation_items' => $installationItems,
                    'order_installations' => $orderInstallations,
                    'installations' => $orderInstallations,
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
                $q->whereIn('status', ['in_progress', 'started', 'stated'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_installable', true)
                            ->where(function ($iiq) {
                                $iiq->whereIn('status', ['in_progress', 'started', 'stated'])
                                    ->orWhereHas('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['in_progress', 'started', 'stated']);
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
                        || in_array($item->status, ['in_progress', 'started', 'stated'])
                        || ($installation && in_array($installation->status, ['in_progress', 'started', 'stated']));

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
                        'id' => $item->id,
                        'order_item_id' => $item->id,
                        'order_id' => $order->id,
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
                        'is_installable' => (int) ($item->is_installable ? 1 : 0),
                        'is_installable_bool' => (bool) $item->is_installable,
                        'is_installable_label' => $item->is_installable ? 'yes' : 'no',
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
                            'notes' => $installation?->notes ?? null,
                            'images' => is_array($installation?->images) ? $installation->images : (json_decode($installation?->images ?? '[]', true) ?: []),
                            'completed_at' => $installation?->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) ($installation?->is_scheduled_assigned ?? true),
                        ],
                        'order_installation' => [
                            'id' => $installation?->id,
                            'order_item_id' => $installation?->order_item_id ?? $item->id,
                            'installation_type' => $installation?->installation_type,
                            'installation_level' => $installation?->installation_level,
                            'status' => 'in_progress',
                            'notes' => $installation?->notes ?? null,
                            'images' => is_array($installation?->images) ? $installation->images : (json_decode($installation?->images ?? '[]', true) ?: []),
                            'completed_at' => $installation?->completed_at?->toIso8601String(),
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

                $orderInstallations = $order->items
                    ->map(function ($it) { return $it->installation; })
                    ->filter()
                    ->map(function ($inst) {
                        return [
                            'id' => $inst->id,
                            'order_item_id' => $inst->order_item_id,
                            'installation_type' => $inst->installation_type,
                            'installation_level' => $inst->installation_level,
                            'status' => $inst->status,
                            'notes' => $inst->notes ?? null,
                            'images' => is_array($inst->images) ? $inst->images : (json_decode($inst->images ?? '[]', true) ?: []),
                            'completed_at' => $inst->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) $inst->is_scheduled_assigned,
                        ];
                    })->values();

                return [
                    'id' => $order->id,
                    'order_id' => $order->id,
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
                    'total_items_count' => $order->items->count(),
                    'items_count' => $mappedItems->count(),
                    'installation_items_count' => $mappedItems->count(),
                    'installations_count' => $orderInstallations->count(),
                    'items' => $mappedItems,
                    'order_items' => $mappedItems,
                    'installation_items' => $mappedItems,
                    'order_installations' => $orderInstallations,
                    'installations' => $orderInstallations,
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
                        'id' => $item->id,
                        'order_item_id' => $item->id,
                        'order_id' => $order->id,
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
                        'is_installable' => (int) ($item->is_installable ? 1 : 0),
                        'is_installable_bool' => (bool) $item->is_installable,
                        'is_installable_label' => $item->is_installable ? 'yes' : 'no',
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
                            'notes' => $installation?->notes ?? null,
                            'images' => is_array($installation?->images) ? $installation->images : (json_decode($installation?->images ?? '[]', true) ?: []),
                            'completed_at' => $installation?->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) ($installation?->is_scheduled_assigned ?? true),
                        ],
                        'order_installation' => [
                            'id' => $installation?->id,
                            'order_item_id' => $installation?->order_item_id ?? $item->id,
                            'installation_type' => $installation?->installation_type,
                            'installation_level' => $installation?->installation_level,
                            'status' => $resolvedStatus,
                            'notes' => $installation?->notes ?? null,
                            'images' => is_array($installation?->images) ? $installation->images : (json_decode($installation?->images ?? '[]', true) ?: []),
                            'completed_at' => $installation?->completed_at?->toIso8601String(),
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

                $orderInstallations = $order->items
                    ->map(function ($it) { return $it->installation; })
                    ->filter()
                    ->map(function ($inst) {
                        return [
                            'id' => $inst->id,
                            'order_item_id' => $inst->order_item_id,
                            'installation_type' => $inst->installation_type,
                            'installation_level' => $inst->installation_level,
                            'status' => $inst->status,
                            'notes' => $inst->notes ?? null,
                            'images' => is_array($inst->images) ? $inst->images : (json_decode($inst->images ?? '[]', true) ?: []),
                            'completed_at' => $inst->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) $inst->is_scheduled_assigned,
                        ];
                    })->values();

                return [
                    'id' => $order->id,
                    'order_id' => $order->id,
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
                    'total_items_count' => $order->items->count(),
                    'items_count' => $mappedItems->count(),
                    'installation_items_count' => $mappedItems->count(),
                    'installations_count' => $orderInstallations->count(),
                    'items' => $mappedItems,
                    'order_items' => $mappedItems,
                    'installation_items' => $mappedItems,
                    'order_installations' => $orderInstallations,
                    'installations' => $orderInstallations,
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
        // Support 'stated', 'started', 'start' -> normalize to 'in_progress'
        if ($normalizedStatus === 'stated' || $normalizedStatus === 'started' || $normalizedStatus === 'start') {
            $normalizedStatus = 'in_progress';
        } elseif ($normalizedStatus === 'complete' || $normalizedStatus === 'finished') {
            $normalizedStatus = 'completed';
        } elseif ($normalizedStatus === 'install') {
            $normalizedStatus = 'installed';
        } elseif ($normalizedStatus === 'schedule') {
            $normalizedStatus = 'scheduled';
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
            return DB::transaction(function () use ($request, $payload, $targetId, $itemId, $orderId, $orderNumber, $rawStatus, $normalizedStatus) {
                $orderItem = null;
                $order = null;
                $orderInstallation = null;

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

                // 3. If targetId was provided (e.g. from URL /update-status/{id})
                if (!$orderItem && $targetId) {
                    // Check if targetId is an OrderInstallation ID
                    $inst = OrderInstallation::find($targetId);
                    if ($inst && $inst->orderItem) {
                        $orderItem = $inst->orderItem;
                        $orderInstallation = $inst;
                        if (!$order) {
                            $order = Order::find($orderItem->order_id);
                        }
                    }
                    if (!$orderItem) {
                        $orderItem = OrderItem::where('id', $targetId)
                            ->orWhere('line_item_id', (string) $targetId)
                            ->first();
                        if ($orderItem && !$order) {
                            $order = Order::find($orderItem->order_id);
                        }
                    }
                    if (!$order && !$orderItem) {
                        $order = Order::find($targetId);
                    }
                }

                // 4. If order is resolved but orderItem is still null, look for installable item within this order
                if ($order && !$orderItem) {
                    $orderItem = $order->items()->where('is_installable', true)->first()
                        ?? $order->items()->first();
                }

                if (!$orderItem && !$order) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Target order or order item not found.',
                    ], 404);
                }

                $oldStatus = $orderInstallation?->status ?? ($orderItem ? $orderItem->status : $order?->status);
                $validItemEnums = ['pending', 'picker_assigned', 'picked', 'packed', 'delivered', 'cancelled'];
                $validOrderEnums = ['pending', 'picking', 'picked', 'packing', 'packed', 'ready_to_assign', 'assigned_to_driver', 'out_for_delivery', 'delivered', 'completed', 'flagged', 'exchange', 'cancelled'];

                // 5. Update single item and its order_installations record
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

                    if (Schema::hasTable('technician_scheduled')) {
                        TechnicianScheduled::where('order_item_id', $orderItem->id)
                            ->update([
                                'status' => $normalizedStatus,
                                'completed_at' => ($normalizedStatus === 'installed' || $normalizedStatus === 'completed') ? now() : null,
                            ]);
                    }

                    if ($order) {
                        if (in_array($normalizedStatus, $validOrderEnums)) {
                            $order->update(['status' => $normalizedStatus]);
                        } elseif ($normalizedStatus === 'installed') {
                            $remaining = OrderItem::where('order_id', $order->id)
                                ->where(function ($remQ) {
                                    $remQ->whereDoesntHave('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['installed', 'completed']);
                                    })
                                        ->whereNotIn('status', ['installed', 'completed', 'delivered']);
                                })
                                ->count();
                            if ($remaining === 0) {
                                $order->update(['status' => 'completed']);
                            }
                        }
                    }
                } elseif ($order) {
                    // 6. Update whole order and all its items' order_installations records
                    if (in_array($normalizedStatus, $validOrderEnums)) {
                        $order->update(['status' => $normalizedStatus]);
                    } elseif ($normalizedStatus === 'installed') {
                        $order->update(['status' => 'completed']);
                    }

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

                    if (Schema::hasTable('technician_scheduled')) {
                        TechnicianScheduled::where('order_id', $order->id)
                            ->update([
                                'status' => $normalizedStatus,
                                'completed_at' => ($normalizedStatus === 'installed' || $normalizedStatus === 'completed') ? now() : null,
                            ]);
                    }
                }

                $userName = $payload['user_name'] ?? $request->input('user_name') ?? (Auth::user()?->name ?? 'Mobile Installer');
                $userId = $payload['user_id'] ?? $request->input('user_id') ?? Auth::id();
                $notes = $payload['notes'] ?? $request->input('notes', "Installation status updated to {$normalizedStatus}" . ($rawStatus !== $normalizedStatus ? " (from '{$rawStatus}')" : ""));

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
                        'input_status' => $rawStatus,
                        'installation_status' => $normalizedStatus,
                        'is_in_progress' => in_array($normalizedStatus, ['in_progress', 'started', 'stated']),
                        'installation' => $orderInstallation ? [
                            'id' => $orderInstallation->id,
                            'order_item_id' => $orderInstallation->order_item_id,
                            'installation_type' => $orderInstallation->installation_type,
                            'installation_level' => $orderInstallation->installation_level,
                            'status' => $orderInstallation->status,
                            'is_scheduled_assigned' => (bool) $orderInstallation->is_scheduled_assigned,
                            'updated_at' => $orderInstallation->updated_at?->toIso8601String(),
                        ] : null,
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

    /**
     * Upload multiple installed proof images and add notes for an order by order_number.
     *
     * Route: GET|POST /api/mobile/scheduled/installed-proof-upload
     *
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Upload installation proof images and store in installation_images table,
     * adding before_install_image and after_install_image fields (both nullable),
     * and storing status, notes, completed_at in order_installations table.
     *
     * Route: GET|POST /api/mobile/scheduled/installed-proof-upload
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function InstalledProofimageupload(Request $request): JsonResponse
    {
        try {
            $orderNumber = $request->input('order_number') ?? $request->query('order_number');
            $orderId = $request->input('order_id') ?? $request->query('order_id');
            $itemId = $request->input('item_id') ?? $request->input('order_item_id') ?? $request->input('line_item_id') ?? $request->query('item_id') ?? $request->query('order_item_id');

            // 1. Validate that order_number or order_id is provided
            if (empty($orderNumber) && empty($orderId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order number or order ID is required. Pass order_number (e.g. #1001 or 1001).',
                ], 422);
            }

            // 2. Resolve Target Order
            $cleanNum = $orderNumber ? trim(ltrim((string) $orderNumber, '#')) : null;
            $order = Order::with('items')->where(function ($q) use ($orderId, $orderNumber, $cleanNum) {
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
                    'message' => "Order not found with order_number '{$orderNumber}'.",
                ], 404);
            }

            // Target item resolution
            $orderItems = $order->items;
            $targetItem = null;
            if (!empty($itemId)) {
                $targetItem = $orderItems->first(function ($it) use ($itemId) {
                    return (string) $it->id === (string) $itemId
                        || (string) $it->line_item_id === (string) $itemId;
                });
            }
            if (!$targetItem) {
                $targetItem = $orderItems->firstWhere('is_installable', true) ?? $orderItems->first();
            }

            // Resolve / ensure primary installation record
            $primaryInstallation = null;
            if ($targetItem) {
                $primaryInstallation = OrderInstallation::firstOrCreate(['order_item_id' => $targetItem->id]);
            } else {
                $primaryInstallation = OrderInstallation::whereIn('order_item_id', $orderItems->pluck('id'))->first();
            }

            // If GET request, return current installation details and installation_images records
            if ($request->isMethod('get')) {
                $storedImages = InstallationImage::where(function ($q) use ($order, $targetItem) {
                    $q->where('order_id', $order->id)
                        ->orWhere('order_number', $order->order_number);
                    if ($targetItem) {
                        $q->orWhere('order_item_id', $targetItem->id);
                    }
                })->latest()->get();

                $latestImage = $storedImages->first();

                $images = [];
                if ($latestImage?->before_install_image) {
                    $images[] = $latestImage->before_install_image;
                }
                if ($latestImage?->after_install_image) {
                    $images[] = $latestImage->after_install_image;
                }
                if (empty($images) && $primaryInstallation?->images) {
                    $images = is_array($primaryInstallation->images)
                        ? $primaryInstallation->images
                        : (json_decode($primaryInstallation->images, true) ?: [$primaryInstallation->images]);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Installed proof details retrieved successfully.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name,
                        'order_status' => $order->status,
                        'item_id' => $targetItem?->id,
                        'order_item_id' => $targetItem?->id,
                        'installation_status' => $primaryInstallation?->status ?? 'scheduled',
                        'notes' => $primaryInstallation?->notes ?? $latestImage?->notes,
                        'before_install_image' => $latestImage?->before_install_image,
                        'after_install_image' => $latestImage?->after_install_image,
                        'images_count' => count($images),
                        'images' => $images,
                        'completed_at' => $primaryInstallation?->completed_at?->toIso8601String(),
                        'installation' => $primaryInstallation,
                        'installation_images' => $storedImages,
                    ],
                ], 200);
            }

            // Setup upload directory
            $uploadDir = public_path('uploads/installations');
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $orderRef = preg_replace('/[^A-Za-z0-9_\-]/', '', $order->order_number) ?: 'order_' . $order->id;

            // Helper to save uploaded file or base64 data to local file and return asset URL
            $saveFileOrBase64 = function ($input, string $prefix) use ($uploadDir, $orderRef): ?string {
                if ($input instanceof \Illuminate\Http\UploadedFile && $input->isValid()) {
                    $ext = $input->getClientOriginalExtension() ?: 'jpg';
                    $filename = $prefix . '_' . $orderRef . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                    $input->move($uploadDir, $filename);
                    return asset('uploads/installations/' . $filename);
                }
                if (is_string($input)) {
                    $trimmed = trim($input);
                    if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
                        return $trimmed;
                    }
                    if (preg_match('/^data:image\/(\w+);base64,/', $trimmed, $typeMatches)) {
                        $b64Data = substr($trimmed, strpos($trimmed, ',') + 1);
                        $ext = strtolower($typeMatches[1]) ?: 'jpg';
                        $decodedData = base64_decode($b64Data);
                        if ($decodedData !== false) {
                            $filename = $prefix . '_' . $orderRef . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                            file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $filename, $decodedData);
                            return asset('uploads/installations/' . $filename);
                        }
                    }
                }
                return null;
            };

            // 3. Process before_install_image (nullable)
            $beforeInput = $request->file('before_install_image')
                ?? $request->file('before_image')
                ?? $request->file('before_installation_image')
                ?? $request->file('before')
                ?? $request->input('before_install_image')
                ?? $request->input('before_image')
                ?? $request->input('before_installation_image')
                ?? $request->input('before');

            $beforeImageUrl = $beforeInput ? $saveFileOrBase64($beforeInput, 'before_install') : null;

            // 4. Process after_install_image (nullable)
            $afterInput = $request->file('after_install_image')
                ?? $request->file('after_image')
                ?? $request->file('after_installation_image')
                ?? $request->file('after')
                ?? $request->input('after_install_image')
                ?? $request->input('after_image')
                ?? $request->input('after_installation_image')
                ?? $request->input('after');

            $afterImageUrl = $afterInput ? $saveFileOrBase64($afterInput, 'after_install') : null;

            // 5. Process any general / multiple uploaded proof images
            $generalImageUrls = [];
            $uploadedFiles = [];
            $fileKeys = ['images', 'photos', 'installed_images', 'files', 'image', 'photo', 'file', 'attachment', 'installed_image', 'proof_images'];

            foreach ($fileKeys as $fKey) {
                if ($request->hasFile($fKey)) {
                    $files = $request->file($fKey);
                    if (is_array($files)) {
                        foreach ($files as $f) {
                            if ($f instanceof \Illuminate\Http\UploadedFile && $f->isValid()) {
                                $uploadedFiles[] = $f;
                            }
                        }
                    } elseif ($files instanceof \Illuminate\Http\UploadedFile && $files->isValid()) {
                        $uploadedFiles[] = $files;
                    }
                }
            }

            foreach ($uploadedFiles as $index => $file) {
                $ext = $file->getClientOriginalExtension() ?: 'jpg';
                $filename = 'proof_' . $orderRef . '_' . time() . '_' . substr(md5(uniqid((string) $index, true)), 0, 6) . '.' . $ext;
                $file->move($uploadDir, $filename);
                $generalImageUrls[] = asset('uploads/installations/' . $filename);
            }

            // Also support input array / base64 array in 'images'
            $inputImages = $request->input('images')
                ?? $request->input('photos')
                ?? $request->input('installed_images')
                ?? $request->input('proof_images');

            if (!empty($inputImages)) {
                if (is_string($inputImages)) {
                    $decoded = json_decode($inputImages, true);
                    $inputImages = is_array($decoded) ? $decoded : [$inputImages];
                }
                if (is_array($inputImages)) {
                    foreach ($inputImages as $imgItem) {
                        $savedUrl = $saveFileOrBase64($imgItem, 'proof');
                        if ($savedUrl) {
                            $generalImageUrls[] = $savedUrl;
                        }
                    }
                }
            }

            // Combine all image URLs
            $allImageUrls = $generalImageUrls;
            if ($beforeImageUrl && !in_array($beforeImageUrl, $allImageUrls)) {
                $allImageUrls[] = $beforeImageUrl;
            }
            if ($afterImageUrl && !in_array($afterImageUrl, $allImageUrls)) {
                $allImageUrls[] = $afterImageUrl;
            }

            // 6. Notes Handling
            $notes = $request->input('notes')
                ?? $request->input('note')
                ?? $request->input('comment')
                ?? $request->input('comments')
                ?? $request->input('description')
                ?? $request->input('remarks');

            // 7. Status & Completed_at Updates
            $oldStatus = $primaryInstallation?->status ?? 'scheduled';
            $statusParam = $request->input('status') ?? $request->input('installation_status');
            $normalizedStatus = null;

            if ($statusParam) {
                $statusParam = strtolower(trim((string) $statusParam));
                if (in_array($statusParam, ['inprogress', 'in_progress', 'started', 'stated'])) {
                    $normalizedStatus = 'in_progress';
                } elseif (in_array($statusParam, ['installed', 'completed', 'done', 'finished'])) {
                    $normalizedStatus = 'installed';
                } else {
                    $normalizedStatus = $statusParam;
                }
            } else {
                // Default status to installed when images are submitted as proof
                $normalizedStatus = 'installed';
            }

            // 8. Find existing record in installation_images table or create new if none exists
            // This ensures before_install_image and after_install_image are stored in the SAME row
            $installationImageQuery = InstallationImage::where(function ($q) use ($order) {
                $q->where('order_id', $order->id)
                    ->orWhere('order_number', $order->order_number);
            });

            if ($targetItem?->id) {
                $installationImageQuery->where('order_item_id', $targetItem->id);
            } elseif ($primaryInstallation?->id) {
                $installationImageQuery->where('order_installation_id', $primaryInstallation->id);
            }

            $installationImage = $installationImageQuery->latest()->first();

            if (!$installationImage) {
                $installationImage = new InstallationImage();
                $installationImage->order_id = $order->id;
                $installationImage->order_number = $order->order_number;
                $installationImage->order_item_id = $targetItem?->id;
                $installationImage->order_installation_id = $primaryInstallation?->id;
            } else {
                if (!$installationImage->order_id) {
                    $installationImage->order_id = $order->id;
                }
                if (!$installationImage->order_number) {
                    $installationImage->order_number = $order->order_number;
                }
                if (!$installationImage->order_item_id && $targetItem?->id) {
                    $installationImage->order_item_id = $targetItem->id;
                }
                if (!$installationImage->order_installation_id && $primaryInstallation?->id) {
                    $installationImage->order_installation_id = $primaryInstallation->id;
                }
            }

            // Only update before_install_image if a new one is provided in this request
            if ($beforeImageUrl !== null) {
                $installationImage->before_install_image = $beforeImageUrl;
            }
            // Only update after_install_image if a new one is provided in this request
            if ($afterImageUrl !== null) {
                $installationImage->after_install_image = $afterImageUrl;
            }
            if ($notes !== null) {
                $installationImage->notes = trim((string) $notes);
            }

            $installationImage->save();

            // Refresh combined images list including both before and after images
            if ($installationImage->before_install_image && !in_array($installationImage->before_install_image, $allImageUrls)) {
                $allImageUrls[] = $installationImage->before_install_image;
            }
            if ($installationImage->after_install_image && !in_array($installationImage->after_install_image, $allImageUrls)) {
                $allImageUrls[] = $installationImage->after_install_image;
            }

            // 9. Store other fields in order_installations table
            $targetItemsToUpdate = $orderItems->where('is_installable', true);
            if ($targetItemsToUpdate->isEmpty() && $targetItem) {
                $targetItemsToUpdate = collect([$targetItem]);
            } elseif ($targetItemsToUpdate->isEmpty()) {
                $targetItemsToUpdate = $orderItems;
            }

            $completedAt = in_array($normalizedStatus, ['installed', 'completed']) ? now() : null;

            foreach ($targetItemsToUpdate as $item) {
                $inst = OrderInstallation::firstOrCreate(['order_item_id' => $item->id]);

                $inst->status = $normalizedStatus;
                $inst->is_scheduled_assigned = true;

                if ($notes !== null) {
                    $inst->notes = trim((string) $notes);
                }
                if ($completedAt) {
                    $inst->completed_at = $completedAt;
                }
                if (!empty($allImageUrls)) {
                    $inst->images = array_values(array_unique($allImageUrls));
                }
                $inst->save();

                // If installed, update order item status if applicable
                $validItemEnums = ['pending', 'picker_assigned', 'picked', 'packed', 'delivered', 'cancelled'];
                if (in_array($normalizedStatus, ['installed', 'completed'])) {
                    $item->update(['status' => 'packed']);
                }
            }

            // 10. Update technician_scheduled if table exists
            if (Schema::hasTable('technician_scheduled')) {
                $techQuery = TechnicianScheduled::where('order_id', $order->id);
                if ($targetItem) {
                    $techQuery->orWhere('order_item_id', $targetItem->id);
                }
                $techQuery->update([
                    'status' => $normalizedStatus,
                    'notes' => $notes !== null ? trim((string) $notes) : null,
                    'images' => !empty($allImageUrls) ? array_values(array_unique($allImageUrls)) : null,
                    'completed_at' => $completedAt,
                ]);
            }

            // 11. Safe update for order status (respecting orders.status enum)
            $validOrderEnums = ['pending', 'picking', 'picked', 'packing', 'packed', 'ready_to_assign', 'assigned_to_driver', 'out_for_delivery', 'delivered', 'completed', 'flagged', 'exchange', 'cancelled'];
            if (in_array($normalizedStatus, $validOrderEnums)) {
                $order->update(['status' => $normalizedStatus]);
            } elseif (in_array($normalizedStatus, ['installed', 'completed'])) {
                $order->update(['status' => 'completed']);
            }

            // 12. Create audit log in order_status_logs
            $userName = $request->input('user_name') ?? (Auth::user()?->name ?? 'Mobile Installer');
            $userId = $request->input('user_id') ?? Auth::id();
            $logAction = (!empty($beforeImageUrl) || !empty($afterImageUrl) || !empty($generalImageUrls))
                ? 'installed_proof_uploaded'
                : 'installation_notes_updated';

            OrderStatusLog::create([
                'order_id' => $order->id,
                'order_item_id' => $targetItem?->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => $logAction,
                'old_status' => $oldStatus,
                'new_status' => $normalizedStatus,
                'notes' => $notes ?? "Uploaded installation proof image(s) for Order #{$order->order_number}",
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Installed proof images and installation data saved successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'order_status' => $order->fresh()->status,
                    'item_id' => $targetItem?->id,
                    'order_item_id' => $targetItem?->id,
                    'installation_status' => $normalizedStatus,
                    'notes' => $notes !== null ? trim((string) $notes) : ($primaryInstallation?->notes ?? null),
                    'before_install_image' => $installationImage->before_install_image,
                    'after_install_image' => $installationImage->after_install_image,
                    'all_images' => array_values(array_unique($allImageUrls)),
                    'completed_at' => $completedAt?->toIso8601String(),
                    'installation_image' => [
                        'id' => $installationImage->id,
                        'order_id' => $installationImage->order_id,
                        'order_number' => $installationImage->order_number,
                        'order_item_id' => $installationImage->order_item_id,
                        'order_installation_id' => $installationImage->order_installation_id,
                        'before_install_image' => $installationImage->before_install_image,
                        'after_install_image' => $installationImage->after_install_image,
                        'notes' => $installationImage->notes,
                        'created_at' => $installationImage->created_at?->toIso8601String(),
                    ],
                    'order_installation' => [
                        'id' => $primaryInstallation?->fresh()->id,
                        'order_item_id' => $primaryInstallation?->fresh()->order_item_id,
                        'installation_type' => $primaryInstallation?->fresh()->installation_type,
                        'installation_level' => $primaryInstallation?->fresh()->installation_level,
                        'status' => $primaryInstallation?->fresh()->status,
                        'notes' => $primaryInstallation?->fresh()->notes,
                        'completed_at' => $primaryInstallation?->fresh()->completed_at?->toIso8601String(),
                        'is_scheduled_assigned' => (bool) $primaryInstallation?->fresh()->is_scheduled_assigned,
                        'updated_at' => $primaryInstallation?->fresh()->updated_at?->toIso8601String(),
                    ],
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in InstalledProofimageupload: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['images', 'photos', 'proof_images']),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload installed proof images and save notes: ' . $e->getMessage(),
            ], 500);
        }
    }
}
