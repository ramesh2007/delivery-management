<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PickerResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
}
