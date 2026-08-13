<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Exception;

class RoleController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the roles.
     */
    public function index(): JsonResponse
    {
        try {
            $roles = Role::with('permissions')->get();
            return $this->successResponse('Roles retrieved successfully', $roles);
        } catch (Exception $e) {
            Log::error('Fetch Roles Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve roles', 500);
        }
    }

    /**
     * Store a newly created role in storage, and optionally assign permissions.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
                'description' => ['nullable', 'string', 'max:255'],
                'permissions' => ['nullable', 'array'],
            ]);

            $role = Role::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            if (isset($data['permissions'])) {
                $this->syncPermissions($role, $data['permissions']);
            }

            return $this->successResponse('Role created successfully', $role->load('permissions'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Create Role Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to create role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified role.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $role = Role::with('permissions')->find($id);

            if (!$role) {
                return $this->errorResponse('Role not found', 404);
            }

            return $this->successResponse('Role retrieved successfully', $role);
        } catch (Exception $e) {
            Log::error('Fetch Role Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve role', 500);
        }
    }

    /**
     * Update the specified role in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return $this->errorResponse('Role not found', 404);
            }

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255', Rule::unique('roles')->ignore($role->id)],
                'description' => ['nullable', 'string', 'max:255'],
                'permissions' => ['nullable', 'array'],
            ]);

            $role->update(array_filter([
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
            ]));

            if (isset($data['permissions'])) {
                $this->syncPermissions($role, $data['permissions']);
            }

            return $this->successResponse('Role updated successfully', $role->load('permissions'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Update Role Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to update role', 500);
        }
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return $this->errorResponse('Role not found', 404);
            }

            $role->delete();

            return $this->successResponse('Role deleted successfully');
        } catch (Exception $e) {
            Log::error('Delete Role Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete role', 500);
        }
    }

    /**
     * Explicitly assign permissions to a role.
     */
    public function assignPermissions(Request $request, int $id): JsonResponse
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return $this->errorResponse('Role not found', 404);
            }

            $data = $request->validate([
                'permissions' => ['required', 'array'],
            ]);

            $this->syncPermissions($role, $data['permissions']);

            return $this->successResponse('Permissions assigned successfully', $role->load('permissions'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Assign Permissions Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to assign permissions', 500);
        }
    }

    /**
     * Resolve permissions from array inputs (IDs or names) and sync them.
     */
    protected function syncPermissions(Role $role, ?array $permissionsInput): void
    {
        if (is_null($permissionsInput)) {
            return;
        }

        $permissionIds = [];
        foreach ($permissionsInput as $item) {
            if (is_numeric($item)) {
                $permissionIds[] = (int)$item;
            } else if (is_string($item)) {
                $permission = Permission::where('name', $item)->first();
                if ($permission) {
                    $permissionIds[] = $permission->id;
                }
            }
        }

        $role->permissions()->sync($permissionIds);
    }
}
