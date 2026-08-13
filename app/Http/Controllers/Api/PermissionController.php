<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Exception;

class PermissionController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the permissions.
     */
    public function index(): JsonResponse
    {
        try {
            $permissions = Permission::all();
            return $this->successResponse('Permissions retrieved successfully', $permissions);
        } catch (Exception $e) {
            Log::error('Fetch Permissions Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve permissions', 500);
        }
    }

    /**
     * Store a newly created permission in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:permissions,name'],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

            $permission = Permission::create($data);

            return $this->successResponse('Permission created successfully', $permission, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Create Permission Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to create permission', 500);
        }
    }

    /**
     * Display the specified permission.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $permission = Permission::find($id);

            if (!$permission) {
                return $this->errorResponse('Permission not found', 404);
            }

            return $this->successResponse('Permission retrieved successfully', $permission);
        } catch (Exception $e) {
            Log::error('Fetch Permission Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve permission', 500);
        }
    }

    /**
     * Update the specified permission in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $permission = Permission::find($id);

            if (!$permission) {
                return $this->errorResponse('Permission not found', 404);
            }

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255', Rule::unique('permissions')->ignore($permission->id)],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

            $permission->update($data);

            return $this->successResponse('Permission updated successfully', $permission);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Update Permission Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to update permission', 500);
        }
    }

    /**
     * Remove the specified permission from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $permission = Permission::find($id);

            if (!$permission) {
                return $this->errorResponse('Permission not found', 404);
            }

            $permission->delete();

            return $this->successResponse('Permission deleted successfully');
        } catch (Exception $e) {
            Log::error('Delete Permission Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete permission', 500);
        }
    }
}
