<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
                'password' => ['required', 'string', 'min:8'], // Postman doesn't always send confirmed well, I removed it for simplicity if they just send 'password'
                'role' => ['nullable', 'string', 'in:Admin,user'],
                'status' => ['nullable', 'string', 'in:active,inactive'],
            ]);

            $data['password'] = Hash::make($data['password']);
            
            $user = User::create($data);
            
            return $this->successResponse('User created successfully', $user, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (Exception $e) {
            Log::error('Create User Error: ' . $e->getMessage());
            return $this->errorResponse('Failed to create user', 500);
        }
    }

    /**
     * Display the specified user.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = User::find($id);

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
                'role' => ['sometimes', 'string', 'in:Admin,user'],
                'status' => ['sometimes', 'string', 'in:active,inactive'],
            ]);
            
            if (isset($data['password']) && !empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            $user->update($data);
            
            return $this->successResponse('User updated successfully', $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
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
}
