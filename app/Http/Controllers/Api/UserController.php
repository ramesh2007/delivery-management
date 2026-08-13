<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Exception;

class UserController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of the users.
     */
    public function index(): JsonResponse
    {
        try {
            $users = User::all();
            return $this->successResponse('Users retrieved successfully', $users);
        } catch (Exception $e) {
            Log::error('Fetch Users Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve users', 500);
        }
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['required', 'string', 'min:8'],
                'role' => ['nullable', 'string', 'exists:roles,name'],
                'roles' => ['nullable', 'array'],
                'status' => ['nullable', 'string', 'in:active,inactive'],
            ]);

            $data['password'] = Hash::make($data['password']);
            
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => $data['status'] ?? 'active',
            ]);

            if (!empty($data['roles'])) {
                $this->syncRoles($user, $data['roles']);
            } elseif (!empty($data['role'])) {
                $this->syncRoles($user, $data['role']);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'User created successfully',
                'user' => $user->load('roles'),
                'token_type' => 'Bearer',
                'access_token' => $token,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Create User Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to create user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified user.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = User::with('roles')->find($id);

            if (!$user) {
                return $this->errorResponse('User not found', 404);
            }

            return $this->successResponse('User retrieved successfully', $user);
        } catch (Exception $e) {
            Log::error('Fetch User Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve user', 500);
        }
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->errorResponse('User not found', 404);
            }

            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['nullable', 'string', 'min:8'],
                'role' => ['nullable', 'string', 'exists:roles,name'],
                'roles' => ['nullable', 'array'],
                'status' => ['sometimes', 'string', 'in:active,inactive'],
            ]);
            
            if (isset($data['password']) && !empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            $user->update($data);

            if (isset($data['roles'])) {
                $this->syncRoles($user, $data['roles']);
            } elseif (isset($data['role'])) {
                $this->syncRoles($user, $data['role']);
            }
            
            return $this->successResponse('User updated successfully', $user->load('roles'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Update User Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to update user', 500);
        }
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->errorResponse('User not found', 404);
            }

            $user->delete();
            
            return $this->successResponse('User deleted successfully');
        } catch (Exception $e) {
            Log::error('Delete User Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete user', 500);
        }
    }

    /**
     * Display a listing of active users.
     */
    public function activeUsers(): JsonResponse
    {
        try {
            $users = User::where('status', 'active')->get();
            return $this->successResponse('Active users retrieved successfully', $users);
        } catch (Exception $e) {
            Log::error('Fetch Active Users Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve active users', 500);
        }
    }

    /**
     * Display a listing of inactive users.
     */
    public function inactiveUsers(): JsonResponse
    {
        try {
            $users = User::where('status', 'inactive')->get();
            return $this->successResponse('Inactive users retrieved successfully', $users);
        } catch (Exception $e) {
            Log::error('Fetch Inactive Users Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve inactive users', 500);
        }
    }

    /**
     * Explicitly assign roles to a user.
     */
    public function assignRoles(Request $request, int $id): JsonResponse
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->errorResponse('User not found', 404);
            }

            $data = $request->validate([
                'roles' => ['required', 'array'],
            ]);

            $this->syncRoles($user, $data['roles']);

            return $this->successResponse('Roles assigned successfully', $user->load('roles'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Assign Roles Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to assign roles', 500);
        }
    }

    /**
     * Sync user roles by name or ID.
     */
    protected function syncRoles(User $user, $rolesInput): void
    {
        if (is_null($rolesInput)) {
            return;
        }

        $roles = is_array($rolesInput) ? $rolesInput : [$rolesInput];
        $roleIds = [];

        foreach ($roles as $item) {
            if (is_numeric($item)) {
                $roleIds[] = (int)$item;
            } elseif (is_string($item)) {
                $role = Role::where('name', strtolower($item))->first();
                if ($role) {
                    $roleIds[] = $role->id;
                }
            }
        }

        $user->roles()->sync($roleIds);
        
        // Also update the role column in users table with the first role's name for compatibility
        if (count($roles) > 0) {
            $firstRole = is_numeric($roles[0]) ? Role::find($roles[0])?->name : $roles[0];
            if ($firstRole) {
                $user->role = $firstRole;
                $user->save();
            }
        }
    }
}
