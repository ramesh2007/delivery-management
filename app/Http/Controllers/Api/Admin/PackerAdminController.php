<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackerResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PackerAdminController extends Controller
{
    /**
     * Get list of packers from users table.
     * Route: GET /api/packers
     * Route: GET /api/get-packers-list
     * Route: GET /api/users/packers
     */
 public function getPackersList(): JsonResponse
    {
        try {
            $packers = User::where('role', 'packer')
                ->orWhere('role', 'Packer')
                ->orWhereHas('roles', function ($query) {
                    $query->whereIn('name', ['packer', 'Packer']);
                })
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Packers list retrieved successfully.',
                'count' => $packers->count(),
                'data' => $packers
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch packers list: ' . $e->getMessage()
            ], 500);
        }
    }
}
