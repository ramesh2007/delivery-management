<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderDriverAssigned;
use App\Models\OrderInstallation;
use App\Models\User;
use App\Http\Controllers\Api\Admin\InstallationLevelController;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    protected ?ShopifyService $shopifyService;

    public function __construct(?ShopifyService $shopifyService = null)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Retrieve statistics for the Picker Mobile Dashboard.
     *
     * The dashboard displays 4 primary cards:
     * 1. New Orders: Total count of all new/unassigned orders available for picking.
     * 2. Total Served: Total number of orders completed by the currently authenticated picker.
     * 3. My Active: Number of in-progress orders currently assigned to the logged-in picker.
     * 4. Completed: Number of orders completed/picked by the currently logged-in picker.
     *
     * Route: GET|POST /api/mobile/dashboard/{picker_id?}
     * Route: GET|POST /api/mobile/picker/dashboard/{picker_id?}
     * Route: GET|POST /api/dashboard/{picker_id?}
     *
     * @param Request $request
     * @param int|string|null $picker_id
     * @return JsonResponse
     */
    public function pickerDashboard(Request $request, $picker_id = null): JsonResponse
    {
        try {
            // Optional Shopify sync if requested
            if ($request->boolean('auto_sync', false) && $this->shopifyService) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                } catch (Throwable $syncEx) {
                    Log::warning('Silent Shopify sync failed in DashboardController: ' . $syncEx->getMessage());
                }
            }

            // =========================================================================
            // 1. Resolve Authenticated Picker / User
            // =========================================================================
            $authUser = Auth::guard('sanctum')->user() ?? $request->user() ?? Auth::user();

            $resolvedId = $picker_id
                ?? $request->route('picker_id')
                ?? $request->route('id')
                ?? $request->route('user_id')
                ?? $request->input('picker_id')
                ?? $request->input('user_id')
                ?? $request->input('assigned_to')
                ?? $request->query('picker_id')
                ?? $request->query('user_id')
                ?? $request->query('assigned_to')
                ?? $authUser?->id;

            if (!empty($resolvedId) && is_numeric($resolvedId)) {
                $resolvedId = (int) $resolvedId;
            }

            $pickerUser = null;
            if ($authUser && (!$resolvedId || $authUser->id == $resolvedId)) {
                $pickerUser = $authUser;
            } elseif (!empty($resolvedId)) {
                $pickerUser = User::find($resolvedId);
            }

            $pickerId = $pickerUser ? $pickerUser->id : $resolvedId;
            $pickerName = $pickerUser
                ? $pickerUser->name
                : ($request->input('picker_name') ?? $request->input('user_name') ?? 'Picker User');

            // If user cannot be resolved and no ID provided, return unauthorized
            if (empty($pickerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated or picker user ID required. Please authenticate with Sanctum or provide picker_id/user_id.',
                ], 401);
            }

            // =========================================================================
            // 2. Card 1: New Orders
            // Count of all new/unassigned orders available for picking.
            // These are pending orders that are unassigned or have unassigned items.
            // =========================================================================
            $newOrdersCount = (int) Order::where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('assigned_to')
                        ->orWhereHas('items', function ($iq) {
                            $iq->whereNull('assigned_to');
                        });
                })
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 3. Card 3: My Active
            // Number of orders currently assigned to the logged-in picker that are
            // still in progress (pending or picking) and have not yet been completed.
            // =========================================================================
            $myActiveCount = (int) Order::where(function ($q) use ($pickerId) {
                $q->where('assigned_to', $pickerId)
                    ->orWhereHas('items', function ($iq) use ($pickerId) {
                        $iq->where('assigned_to', $pickerId);
                    });
            })
                ->whereIn('status', ['pending', 'picking'])
                ->where(function ($q) use ($pickerId) {
                    // Items assigned to this picker that are still in progress
                    $q->whereHas('items', function ($iq) use ($pickerId) {
                        $iq->where(function ($siq) use ($pickerId) {
                            $siq->where('assigned_to', $pickerId)
                                ->orWhereNull('assigned_to');
                        })->whereNotIn('status', ['picked', 'packed', 'delivered', 'cancelled']);
                    })
                        // Or order level assignment where order is still in progress
                        ->orWhere(function ($oq) use ($pickerId) {
                        $oq->where('assigned_to', $pickerId)
                            ->whereNotIn('status', ['picked', 'packed', 'delivered', 'cancelled']);
                    });
                })
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 4. Card 4: Completed
            // Number of orders completed/picked by the currently logged-in picker.
            // Reuses picker assignment and completion status (picked or completed).
            // =========================================================================
            $completedStatusCount = (int) Order::where(function ($q) {
                $q->where('status', 'picked')
                    ->orWhere('status', 'completed');
            })
                ->where(function ($q) use ($pickerId) {
                    $q->where('assigned_to', $pickerId)
                        ->orWhereHas('items', function ($iq) use ($pickerId) {
                            $iq->where('picked_by', $pickerId)
                                ->orWhere(function ($sub) use ($pickerId) {
                                    $sub->where('assigned_to', $pickerId)
                                        ->whereIn('status', ['picked', 'completed']);
                                });
                        });
                })
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 5. Card 2: Total Served
            // Total number of orders completed/served by the current picker across
            // all time (including orders that subsequently moved to packing/delivery).
            // =========================================================================
            $totalServedCount = (int) Order::where(function ($q) use ($pickerId) {
                $q->where(function ($sub) use ($pickerId) {
                    $sub->where('assigned_to', $pickerId)
                        ->whereIn('status', [
                            'picked',
                            'packing',
                            'packed',
                            'in_delivery',
                            'assigned_to_driver',
                            'started',
                            'delivered',
                            'completed',
                        ]);
                })
                    ->orWhereHas('items', function ($iq) use ($pickerId) {
                        $iq->where('picked_by', $pickerId)
                            ->orWhere(function ($sub) use ($pickerId) {
                                $sub->where('assigned_to', $pickerId)
                                    ->whereIn('status', [
                                        'picked',
                                        'packed',
                                        'delivered',
                                        'completed',
                                    ]);
                            });
                    });
            })
                ->distinct()
                ->count('orders.id');

            // If completed status count is available, use it; otherwise fallback to total served
            // to ensure completed orders that advanced in the workflow are properly represented.
            $finalCompletedCount = $completedStatusCount > 0 ? $completedStatusCount : $totalServedCount;

            // =========================================================================
            // 6. Response Payload — only the 4 dashboard card counts
            // =========================================================================
            return response()->json([
                'success' => true,
                'message' => 'Picker dashboard statistics retrieved successfully.',
                'new_orders' => $newOrdersCount,
                'total_served' => $totalServedCount,
                'my_active' => $myActiveCount,
                'completed' => $finalCompletedCount,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Picker Dashboard Controller Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve picker dashboard statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mobile Packer Dashboard Statistics API
     *
     * Returns 4 key metric card counts for the Packer Mobile Dashboard:
     * 1. New Orders: Count of all available new orders ready for packing (status 'picked', unassigned to packer).
     * 2. Total Served: Count of orders completed/packed by the currently logged-in packer across all time.
     * 3. My Active: Count of orders currently assigned to the logged-in packer that are in progress ('picked' or 'packing').
     * 4. Completed: Count of orders completed/packed by the currently logged-in packer.
     *
     * Route: GET|POST /api/mobile/packer/dashboard/{packer_id?}
     * Route: GET|POST /api/packer/dashboard/{packer_id?}
     *
     * @param Request $request
     * @param int|string|null $packer_id
     * @return JsonResponse
     */
    public function packerDashboard(Request $request, $packer_id = null): JsonResponse
    {
        try {
            // Optional Shopify sync if requested
            if ($request->boolean('auto_sync', false) && $this->shopifyService) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                } catch (Throwable $syncEx) {
                    Log::warning('Silent Shopify sync failed in DashboardController: ' . $syncEx->getMessage());
                }
            }

            // =========================================================================
            // 1. Resolve Authenticated Packer / User
            // =========================================================================
            $authUser = Auth::guard('sanctum')->user() ?? $request->user() ?? Auth::user();

            $resolvedId = $packer_id
                ?? $request->route('packer_id')
                ?? $request->route('id')
                ?? $request->route('user_id')
                ?? $request->input('packer_id')
                ?? $request->input('user_id')
                ?? $request->input('assigned_to')
                ?? $request->query('packer_id')
                ?? $request->query('user_id')
                ?? $request->query('assigned_to')
                ?? $authUser?->id;

            if (!empty($resolvedId) && is_numeric($resolvedId)) {
                $resolvedId = (int) $resolvedId;
            }

            $packerUser = null;
            if ($authUser && (!$resolvedId || $authUser->id == $resolvedId)) {
                $packerUser = $authUser;
            } elseif (!empty($resolvedId)) {
                $packerUser = User::find($resolvedId);
            }

            $packerId = $packerUser ? $packerUser->id : $resolvedId;
            $packerName = $packerUser
                ? $packerUser->name
                : ($request->input('packer_name') ?? $request->input('user_name') ?? null);

            // If user cannot be resolved and no ID provided, return unauthorized
            if (empty($packerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated or packer user ID required. Please authenticate with Sanctum or provide packer_id/user_id.',
                ], 401);
            }

            // =========================================================================
            // 2. Card 1: New Orders
            // All available new orders count ready for packing.
            // Orders with status 'picked' that are unassigned to any packer or have
            // unassigned items needing packing/verification.
            // =========================================================================
            $newOrdersCount = (int) Order::whereIn('status', ['picked', 'ready_to_pack'])
                ->where(function ($q) {
                    $q->where(function ($uq) {
                        $uq->where(function ($sq) {
                            $sq->whereNull('packed_by')
                               ->orWhere('packed_by', '');
                        })->where(function ($sq) {
                            $sq->whereDoesntHave('packerAssignment')
                               ->orWhereHas('packerAssignment', function ($pa) {
                                   $pa->whereNull('packer_assigned_user_id')
                                      ->orWhere('packer_assigned_user_id', '');
                               });
                        });
                    })
                    ->orWhereHas('items', function ($iq) {
                        $iq->whereNull('packed_by');
                    });
                })
                ->where(function ($q) {
                    $q->whereDoesntHave('items')
                      ->orWhereHas('items', function ($iq) {
                          $iq->where('is_packer_verified', false)
                             ->orWhereNull('is_packer_verified')
                             ->orWhereNotIn('status', ['packed', 'delivered', 'completed']);
                      });
                })
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 3. Card 3: My Active
            // Orders currently assigned to logged-in packer that are in progress
            // (status 'picked' or 'packing') and have not yet completed packing.
            // =========================================================================
            $myActiveCount = (int) Order::where(function ($q) use ($packerId, $packerName) {
                $q->where('packed_by', $packerId)
                    ->orWhereHas('packerAssignment', function ($pa) use ($packerId, $packerName) {
                        $pa->where('packer_assigned_user_id', $packerId);
                        if (!empty($packerName)) {
                            $pa->orWhere('packer_assigned_user_name', $packerName);
                        }
                    })
                    ->orWhereHas('items', function ($iq) use ($packerId, $packerName) {
                        $iq->where('packed_by', $packerId);
                        if (!empty($packerName)) {
                            $iq->orWhere('packed_user_name', $packerName);
                        }
                    });
                if (!empty($packerName)) {
                    $q->orWhere('packed_user_name', $packerName);
                }
            })
                ->whereIn('status', ['picked', 'packing'])
                ->whereNotIn('status', ['packed', 'ready_to_assign', 'assigned_to_driver', 'started', 'in_delivery', 'delivered', 'completed', 'cancelled'])
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 4. Card 4: Completed
            // Orders completed/packed by logged-in packer (status 'packed', 'ready_to_assign',
            // or 'completed', or with packed items).
            // =========================================================================
            $completedStatusCount = (int) Order::where(function ($q) {
                $q->whereIn('status', ['packed', 'ready_to_assign', 'completed'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->whereIn('status', ['packed', 'completed']);
                    });
            })
                ->where(function ($q) use ($packerId, $packerName) {
                    $q->where('packed_by', $packerId)
                        ->orWhereHas('packerAssignment', function ($pa) use ($packerId, $packerName) {
                            $pa->where('packer_assigned_user_id', $packerId);
                            if (!empty($packerName)) {
                                $pa->orWhere('packer_assigned_user_name', $packerName);
                            }
                        })
                        ->orWhereHas('items', function ($iq) use ($packerId, $packerName) {
                            $iq->where(function ($sub) use ($packerId, $packerName) {
                                $sub->where('packed_by', $packerId)
                                    ->orWhere('packer_verified_by', $packerId);
                                if (!empty($packerName)) {
                                    $sub->orWhere('packed_user_name', $packerName);
                                }
                            })
                            ->whereIn('status', ['packed', 'completed']);
                        });
                    if (!empty($packerName)) {
                        $q->orWhere('packed_user_name', $packerName);
                    }
                })
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 5. Card 2: Total Served
            // Orders completed/packed by logged-in packer across all time (including
            // orders that subsequently moved to delivery or completed).
            // =========================================================================
            $totalServedCount = (int) Order::where(function ($q) use ($packerId, $packerName) {
                $q->where(function ($sub) use ($packerId, $packerName) {
                    $sub->where(function ($uq) use ($packerId, $packerName) {
                        $uq->where('packed_by', $packerId);
                        if (!empty($packerName)) {
                            $uq->orWhere('packed_user_name', $packerName);
                        }
                    })
                    ->whereIn('status', [
                        'packed',
                        'ready_to_assign',
                        'assigned_to_driver',
                        'started',
                        'in_delivery',
                        'delivered',
                        'completed',
                    ]);
                })
                    ->orWhereHas('packerAssignment', function ($pa) use ($packerId, $packerName) {
                        $pa->where(function ($pu) use ($packerId, $packerName) {
                            $pu->where('packer_assigned_user_id', $packerId);
                            if (!empty($packerName)) {
                                $pu->orWhere('packer_assigned_user_name', $packerName);
                            }
                        })
                        ->whereHas('order', function ($oq) {
                            $oq->whereIn('status', [
                                'packed',
                                'ready_to_assign',
                                'assigned_to_driver',
                                'started',
                                'in_delivery',
                                'delivered',
                                'completed',
                            ]);
                        });
                    })
                    ->orWhereHas('items', function ($iq) use ($packerId, $packerName) {
                        $iq->where(function ($sub) use ($packerId, $packerName) {
                            $sub->where('packed_by', $packerId)
                                ->orWhere('packer_verified_by', $packerId);
                            if (!empty($packerName)) {
                                $sub->orWhere('packed_user_name', $packerName);
                            }
                        })
                        ->whereIn('status', [
                            'packed',
                            'delivered',
                            'completed',
                        ]);
                    });
            })
                ->distinct()
                ->count('orders.id');

            // If completed status count is available, use it; otherwise fallback to total served
            $finalCompletedCount = $completedStatusCount > 0 ? $completedStatusCount : $totalServedCount;

            // =========================================================================
            // 6. Response Payload — only the 4 dashboard card counts
            // =========================================================================
            return response()->json([
                'success' => true,
                'message' => 'Packer dashboard statistics retrieved successfully.',
                'new_orders' => $newOrdersCount,
                'total_served' => $totalServedCount,
                'my_active' => $myActiveCount,
                'completed' => $finalCompletedCount,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Packer Dashboard Controller Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve packer dashboard statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mobile Driver Dashboard Statistics API
     *
     * Returns 3 key metric card counts for the Driver Mobile Dashboard:
     * 1. Total Assigned Orders: Total orders assigned to the logged-in driver.
     * 2. Pending Deliveries: Orders currently assigned to the driver that are pending delivery (in progress).
     * 3. Completed Deliveries: Orders successfully delivered or completed by the driver.
     *
     * Route: GET|POST /api/mobile/driver/dashboard/{driver_id?}
     * Route: GET|POST /api/driver/dashboard/{driver_id?}
     *
     * @param Request $request
     * @param int|string|null $driver_id
     * @return JsonResponse
     */
    public function driverDashboard(Request $request, $driver_id = null): JsonResponse
    {
        try {
            // Optional Shopify sync if requested
            if ($request->boolean('auto_sync', false) && $this->shopifyService) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                } catch (Throwable $syncEx) {
                    Log::warning('Silent Shopify sync failed in DashboardController: ' . $syncEx->getMessage());
                }
            }

            // =========================================================================
            // 1. Resolve Authenticated Driver / User
            // =========================================================================
            $authUser = Auth::guard('sanctum')->user() ?? $request->user() ?? Auth::user();

            $resolvedId = $driver_id
                ?? $request->route('driver_id')
                ?? $request->route('driver_user_id')
                ?? $request->route('id')
                ?? $request->route('user_id')
                ?? $request->input('driver_id')
                ?? $request->input('driver_user_id')
                ?? $request->input('user_id')
                ?? $request->input('assigned_to')
                ?? $request->query('driver_id')
                ?? $request->query('driver_user_id')
                ?? $request->query('user_id')
                ?? $request->query('assigned_to')
                ?? $authUser?->id;

            if (!empty($resolvedId) && is_numeric($resolvedId)) {
                $resolvedId = (int) $resolvedId;
            }

            $driverUser = null;
            if ($authUser && (!$resolvedId || $authUser->id == $resolvedId)) {
                $driverUser = $authUser;
            } elseif (!empty($resolvedId)) {
                $driverUser = User::find($resolvedId);
            }

            $driverId = $driverUser ? $driverUser->id : $resolvedId;
            $driverName = $driverUser
                ? $driverUser->name
                : ($request->input('driver_name') ?? $request->input('user_name') ?? null);

            // If user cannot be resolved and no ID provided, return unauthorized
            if (empty($driverId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated or driver user ID required. Please authenticate with Sanctum or provide driver_id/user_id.',
                ], 401);
            }

            // Driver assignment filter closure
            $driverOrderFilter = function ($q) use ($driverId, $driverName) {
                $q->where('delivered_by', $driverId)
                    ->orWhereHas('driverAssignment', function ($da) use ($driverId, $driverName) {
                        $da->where('assigned_driver_user_id', $driverId);
                        if (!empty($driverName)) {
                            $da->orWhere('driver_name', $driverName);
                        }
                    });
                if (!empty($driverName)) {
                    $q->orWhere('delivered_user_name', $driverName);
                }
            };

            // =========================================================================
            // 2. Card 1: Total Assigned Orders
            // Total number of orders assigned to the logged-in driver.
            // =========================================================================
            $totalAssignedOrders = (int) Order::where($driverOrderFilter)
                ->distinct()
                ->count('orders.id');

            $directAssignmentCount = (int) OrderDriverAssigned::where('assigned_driver_user_id', $driverId)
                ->distinct('order_id')
                ->count('order_id');

            if ($directAssignmentCount > $totalAssignedOrders) {
                $totalAssignedOrders = $directAssignmentCount;
            }

            // =========================================================================
            // 3. Card 2: Pending Deliveries
            // Orders currently assigned to the driver that are in progress and pending delivery
            // (driver_status in 'assigned', 'accepted', 'started', or order status in
            // 'assigned_to_driver', 'started', 'in_delivery', 'out_for_delivery').
            // =========================================================================
            $pendingDeliveriesCount = (int) Order::where($driverOrderFilter)
                ->where(function ($q) {
                    $q->whereIn('status', ['assigned_to_driver', 'started', 'in_delivery', 'out_for_delivery'])
                        ->orWhereHas('driverAssignment', function ($da) {
                            $da->whereIn('driver_status', ['assigned', 'accepted', 'started']);
                        });
                })
                ->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'refund', 'exchange'])
                ->whereDoesntHave('driverAssignment', function ($da) {
                    $da->whereIn('driver_status', ['delivered', 'cancelled', 'refund', 'exchange']);
                })
                ->distinct()
                ->count('orders.id');

            // =========================================================================
            // 4. Card 3: Completed Deliveries
            // Orders successfully delivered or completed by the logged-in driver
            // (driver_status = 'delivered', or order status in 'delivered', 'completed').
            // =========================================================================
            $completedDeliveriesCount = (int) Order::where($driverOrderFilter)
                ->where(function ($q) {
                    $q->whereIn('status', ['delivered', 'completed'])
                        ->orWhereHas('driverAssignment', function ($da) {
                            $da->where('driver_status', 'delivered');
                        });
                })
                ->distinct()
                ->count('orders.id');

            // Fallback: If completed count in Order table is 0, check OrderDriverAssigned delivered count directly
            $directDeliveredCount = (int) OrderDriverAssigned::where('assigned_driver_user_id', $driverId)
                ->where('driver_status', 'delivered')
                ->distinct('order_id')
                ->count('order_id');

            if ($directDeliveredCount > $completedDeliveriesCount) {
                $completedDeliveriesCount = $directDeliveredCount;
            }

            // =========================================================================
            // 5. Response Payload — the 3 dashboard card counts
            // =========================================================================
            return response()->json([
                'success' => true,
                'message' => 'Driver dashboard statistics retrieved successfully.',
                'total_assigned_orders' => $totalAssignedOrders,
                'pending_deliveries' => $pendingDeliveriesCount,
                'completed_deliveries' => $completedDeliveriesCount,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Driver Dashboard Controller Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve driver dashboard statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mobile Installer Dashboard Statistics API
     *
     * Returns 4 key metric card counts for the Installer Mobile Dashboard:
     * 1. New Orders: Count of available new/unassigned installation orders.
     * 2. Total Served: Total count of installations completed/installed by this installer across all time.
     * 3. My Active: Count of in-progress installation orders currently assigned to the logged-in installer.
     * 4. Completed: Count of completed installation orders for the logged-in installer.
     *
     * Route: GET|POST /api/mobile/installer/dashboard/{installer_id?}
     * Route: GET|POST /api/installer/dashboard/{installer_id?}
     *
     * @param Request $request
     * @param int|string|null $installer_id
     * @return JsonResponse
     */
    public function installationDashboard(Request $request, $installer_id = null): JsonResponse
    {
        try {
            // Optional Shopify sync if requested
            if ($request->boolean('auto_sync', false) && $this->shopifyService) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                } catch (Throwable $syncEx) {
                    Log::warning('Silent Shopify sync failed in DashboardController: ' . $syncEx->getMessage());
                }
            }

            // =========================================================================
            // 1. Resolve Authenticated Installer / User
            // =========================================================================
            $authUser = Auth::guard('sanctum')->user() ?? $request->user() ?? Auth::user();

            $resolvedId = $installer_id
                ?? $request->route('installer_id')
                ?? $request->route('installer_userid')
                ?? $request->route('id')
                ?? $request->route('user_id')
                ?? $request->input('installer_id')
                ?? $request->input('installer_userid')
                ?? $request->input('user_id')
                ?? $request->input('assigned_to')
                ?? $request->query('installer_id')
                ?? $request->query('installer_userid')
                ?? $request->query('user_id')
                ?? $request->query('assigned_to')
                ?? $authUser?->id;

            if (!empty($resolvedId) && is_numeric($resolvedId)) {
                $resolvedId = (int) $resolvedId;
            }

            $installerUser = null;
            if ($authUser && (!$resolvedId || $authUser->id == $resolvedId)) {
                $installerUser = $authUser;
            } elseif (!empty($resolvedId)) {
                $installerUser = User::find($resolvedId);
            }

            $installerId = $installerUser ? $installerUser->id : $resolvedId;
            $installerName = $installerUser
                ? $installerUser->name
                : ($request->input('installer_name') ?? $request->input('user_name') ?? null);

            if (!$installerName && class_exists(InstallationLevelController::class) && !empty($installerId)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($installerId) {
                        return (string) ($t['id'] ?? '') === (string) $installerId
                            || (string) ($t['technician_id'] ?? '') === (string) $installerId;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // If user cannot be resolved and no ID provided, return unauthorized
            if (empty($installerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated or installer user ID required. Please authenticate with Sanctum or provide installer_id/user_id.',
                ], 401);
            }

            // =========================================================================
            // 2. Card 1: New Orders
            // All available new/unassigned installation orders.
            // Orders that contain installable items or installation records that are
            // not yet assigned to any installer and not completed/cancelled.
            // =========================================================================
            $newOrdersCount = (int) Order::whereHas('items', function ($iq) {
                $iq->where(function ($sub) {
                    $sub->where('is_installable', true)
                        ->orWhereHas('installation');
                });
            })
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('assigned_to')
                        ->orWhere('assigned_to', '');
                })
                ->orWhereHas('items', function ($iq) {
                    $iq->where(function ($sub) {
                        $sub->where('is_installable', true)
                            ->orWhereHas('installation');
                    })
                    ->where(function ($sub) {
                        $sub->whereNull('assigned_to')
                            ->orWhere('assigned_to', '');
                    });
                });
            })
            ->whereNotIn('status', ['completed', 'installed', 'cancelled'])
            ->whereDoesntHave('items', function ($iq) {
                $iq->whereHas('installation', function ($instQ) {
                    $instQ->whereIn('status', ['completed', 'installed']);
                });
            })
            ->distinct()
            ->count('orders.id');

            // Filter for orders where this installer is assigned
            $installerFilter = function ($q) use ($installerId, $installerName) {
                $q->where(function ($sub) use ($installerId, $installerName) {
                    $sub->where('assigned_to', $installerId);
                    if (!empty($installerName)) {
                        $sub->orWhere('assigned_user_name', $installerName);
                    }
                })
                ->orWhereHas('items', function ($iq) use ($installerId, $installerName) {
                    $iq->where(function ($siq) {
                        $siq->where('is_installable', true)
                            ->orWhereHas('installation');
                    })
                    ->where(function ($siq) use ($installerId, $installerName) {
                        $siq->where('assigned_to', $installerId);
                        if (!empty($installerName)) {
                            $siq->orWhere('assigned_user_name', $installerName);
                        }
                    });
                })
                ->orWhereHas('logs', function ($lq) use ($installerId) {
                    $lq->where('user_id', $installerId)
                        ->whereIn('action', [
                            'scheduled_installation',
                            'installation_assigned',
                            'scheduled_installation_mobile',
                            'update_installation_status'
                        ]);
                });
            };

            // =========================================================================
            // 3. Card 3: My Active
            // In-progress installation orders assigned to this installer.
            // Matches getInProgressInstallationsByInstallerUserId in ScheduledInstallationMobileController.
            // =========================================================================
            $myActiveCount = (int) Order::whereHas('items', function ($iq) {
                $iq->where('is_installable', true)
                    ->orWhereHas('installation');
            })
            ->where($installerFilter)
            ->where(function ($q) {
                $q->whereIn('status', ['in_progress', 'started', 'scheduled', 'assigned'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->where(function ($sub) {
                            $sub->where('is_installable', true)
                                ->orWhereHas('installation');
                        })
                        ->where(function ($iiq) {
                            $iiq->whereIn('status', ['in_progress', 'started'])
                                ->orWhereHas('installation', function ($instQ) {
                                    $instQ->whereIn('status', ['in_progress', 'started', 'scheduled']);
                                });
                        });
                    });
            })
            ->distinct()
            ->count('orders.id');

            // =========================================================================
            // 4. Card 4: Completed
            // Installation orders completed/installed by this installer.
            // Matches getCompletedInstallationsByInstallerUserId in ScheduledInstallationMobileController.
            // =========================================================================
            $completedCount = (int) Order::whereHas('items', function ($iq) {
                $iq->where('is_installable', true)
                    ->orWhereHas('installation');
            })
            ->where($installerFilter)
            ->where(function ($q) {
                $q->whereIn('status', ['completed', 'installed'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->where(function ($sub) {
                            $sub->where('is_installable', true)
                                ->orWhereHas('installation');
                        })
                        ->where(function ($iiq) {
                            $iiq->whereIn('status', ['completed', 'installed'])
                                ->orWhereHas('installation', function ($instQ) {
                                    $instQ->whereIn('status', ['completed', 'installed']);
                                });
                        });
                    });
            })
            ->distinct()
            ->count('orders.id');

            // =========================================================================
            // 5. Card 2: Total Served
            // Total installations completed/installed across all time.
            // =========================================================================
            $totalServedCount = $completedCount;

            // =========================================================================
            // 6. Response Payload — the 4 dashboard card counts
            // =========================================================================
            return response()->json([
                'success' => true,
                'message' => 'Installer dashboard statistics retrieved successfully.',
                'new_orders' => $newOrdersCount,
                'total_served' => $totalServedCount,
                'my_active' => $myActiveCount,
                'completed' => $completedCount,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Installer Dashboard Controller Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve installer dashboard statistics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
