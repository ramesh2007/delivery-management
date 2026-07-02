<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\AuthService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class AuthController extends Controller
{
    use ApiResponse;

    protected $authService;

    /**
     * AuthController constructor.
     *
     * @param AuthService $authService
     */
    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle user login.
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login($request->validated());

            return $this->successResponse('Login successful', $data);
        } catch (Exception $e) {
            Log::error('Login Error: ' . $e->getMessage());

            if ($e->getMessage() === 'Invalid credentials' || $e->getMessage() === 'Your account is inactive.') {
                return $this->errorResponse($e->getMessage(), 401);
            }

            return $this->errorResponse('An error occurred during login', 500);
        }
    }

    /**
     * Handle user logout.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return $this->successResponse('Logout successful');
        } catch (Exception $e) {
            Log::error('Logout Error: ' . $e->getMessage());
            return $this->errorResponse('An error occurred during logout', 500);
        }
    }

    /**
     * Handle user change password.
     *
     * @param ChangePasswordRequest $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->changePassword($request->user(), $request->validated('new_password'));

            return $this->successResponse('Password changed successfully');
        } catch (Exception $e) {
            Log::error('Change Password Error: ' . $e->getMessage());
            return $this->errorResponse('An error occurred while changing password', 500);
        }
    }
}
