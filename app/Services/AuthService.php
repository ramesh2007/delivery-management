<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Exception;

class AuthService
{
    protected $userRepository;
    protected ERPNextAuthService $erpnextAuthService;

    /**
     * AuthService constructor.
     *
     * @param UserRepositoryInterface $userRepository
     * @param ERPNextAuthService $erpnextAuthService
     */
    public function __construct(UserRepositoryInterface $userRepository, ERPNextAuthService $erpnextAuthService)
    {
        $this->userRepository = $userRepository;
        $this->erpnextAuthService = $erpnextAuthService;
    }

    /**
     * Attempt to authenticate a user via ERPNext, sync local user & roles, and issue Sanctum token.
     *
     * @param array $credentials
     * @return array
     * @throws Exception
     */
    public function login(array $credentials): array
    {
        $identifier = $credentials['username'] ?? $credentials['email'] ?? '';
        $password = $credentials['password'] ?? '';
    
        if (empty($identifier) || empty($password)) {
            throw new Exception('Username/Email and Password are required');
        }
    
        try {
            // ============================================================
            // 1. Authenticate with ERPNext
            // ============================================================
    
            $erpResult = $this->erpnextAuthService->authenticate(
                $identifier,
                $password
            );
    
            $userEmail = $erpResult['email'] ?? null;
            $userName = $erpResult['name'] ?? null;
            $erpnextUsername = $erpResult['username'] ?? null;
    
            $erpnextUserId = $erpResult['erpnext_user_id'] ?? null;
    
            $erpnextApiKey = $erpResult['erpnext_api_key'] ?? null;
            $erpnextApiSecret = $erpResult['erpnext_api_secret'] ?? null;
            $erpnextToken = $erpResult['erpnext_token'] ?? null;
    
            $erpnextRoles = $erpResult['roles'] ?? [];
    
            // ============================================================
            // Employee + Warehouse come directly from ERPNext
            // DO NOT save these to local users table
            // ============================================================
    
            $erpnextEmpID = $erpResult['employee_id'] ?? null;
    
            $erpnextActiveWarehouse = $erpResult['active_warehouse'] ?? null;
    
    
            // ============================================================
            // 2. Find existing local user
            // ============================================================
    
            $user = null;
    
            if ($userEmail) {
                $user = User::where('email', $userEmail)->first();
            }
    
            if (!$user && $erpnextUsername) {
                $user = User::where('username', $erpnextUsername)->first();
            }
    
            if (!$user && $identifier) {
                $user = User::where('username', $identifier)
                    ->orWhere('email', $identifier)
                    ->first();
            }
    
    
            // ============================================================
            // 3. Get existing API credentials from local DB
            // ============================================================
    
            $dbApiKey = $user ? $user->erpnext_api_key : null;
            $dbApiSecret = $user ? $user->erpnext_api_secret : null;
    
    
            // ============================================================
            // Extract API key / secret from existing token if needed
            // ============================================================
    
            if (
                $user &&
                (!$dbApiKey || !$dbApiSecret) &&
                !empty($user->erpnext_token) &&
                str_starts_with($user->erpnext_token, 'token ')
            ) {
                $parts = explode(
                    ':',
                    str_replace('token ', '', $user->erpnext_token)
                );
    
                if (count($parts) === 2) {
                    $dbApiKey = $dbApiKey ?: $parts[0];
                    $dbApiSecret = $dbApiSecret ?: $parts[1];
                }
            }
    
    
            // ============================================================
            // 4. Determine final ERPNext API credentials
            // ============================================================
    
            $finalApiKey = !empty($erpnextApiKey)
                ? $erpnextApiKey
                : $dbApiKey;
    
            $finalApiSecret = !empty($erpnextApiSecret)
                ? $erpnextApiSecret
                : $dbApiSecret;
    
    
            // ============================================================
            // 5. Determine final ERPNext token
            // ============================================================
    
            if (
                !empty($finalApiKey) &&
                !empty($finalApiSecret)
            ) {
                $finalToken =
                    "token {$finalApiKey}:{$finalApiSecret}";
            } elseif (!empty($erpnextToken)) {
                $finalToken = $erpnextToken;
            } else {
                $finalToken = $user
                    ? $user->erpnext_token
                    : null;
            }
    
    
            // ============================================================
            // 6. Prepare local user payload
            //
            // IMPORTANT:
            // employee_id and active_warehouse are NOT included here.
            // They are NOT saved in users table.
            // ============================================================
    
            $userPayload = [
    
                'name' => $userName
                    ?: ($user ? $user->name : $identifier),
    
                'email' => $userEmail
                    ?: (
                        $user
                            ? $user->email
                            : (
                                $erpnextUsername
                                    ? $erpnextUsername . '@erpnext.local'
                                    : $identifier . '@erpnext.local'
                            )
                    ),
    
                'username' => $erpnextUsername
                    ?: ($user ? $user->username : $identifier),
    
                'password' => Hash::make($password),
    
                'status' => 'active',
    
                'role' => $erpnextRoles[0]
                    ?? ($user ? $user->role : 'User'),
    
                'erpnext_user_id' => $erpnextUserId
                    ?: ($user ? $user->erpnext_user_id : null),
    
                'erpnext_api_key' => $finalApiKey,
    
                'erpnext_api_secret' => $finalApiSecret,
    
                // DO NOT SAVE:
                // 'erpnext_employee_id'
                // 'erpnext_active_warehouse'
    
                'erpnext_token' => $finalToken,
    
                'erpnext_synced_at' => now(),
            ];
    
    
            // ============================================================
            // 7. Only save columns that actually exist
            // ============================================================
    
            $validColumns = array_filter(
                array_keys($userPayload),
                fn($column) => Schema::hasColumn('users', $column)
            );
    
            $userPayload = array_intersect_key(
                $userPayload,
                array_flip($validColumns)
            );
    
    
            // ============================================================
            // 8. Create / Update local user
            // ============================================================
    
            if (!$user) {
                $user = User::create($userPayload);
            } else {
                $user->update($userPayload);
            }
    
    
            // ============================================================
            // 9. Sync ERPNext Roles
            // ============================================================
    
            if (!empty($erpnextRoles)) {
    
                $roleIds = [];
    
                foreach ($erpnextRoles as $roleName) {
    
                    $roleModel = Role::firstOrCreate([
                        'name' => $roleName
                    ]);
    
                    $roleIds[] = $roleModel->id;
                }
    
                $user->roles()->sync($roleIds);
            }
    
    
            // ============================================================
            // 10. Revoke old Sanctum tokens
            // ============================================================
    
            $user->tokens()->delete();
    
            $token = $user
                ->createToken('auth_token')
                ->plainTextToken;
    
    
            // ============================================================
            // 11. Load roles
            // ============================================================
    
            $user->load('roles');
    
            $assignedRoles = $user
                ->roles
                ->pluck('name')
                ->toArray();
    
            if (
                empty($assignedRoles) &&
                !empty($user->role)
            ) {
                $assignedRoles = [$user->role];
            }
    
    
            // ============================================================
            // 12. Refresh local user
            // ============================================================
    
            $user->refresh();
    
            $user->load('roles');
    
            $assignedRoles = $user
                ->roles
                ->pluck('name')
                ->toArray();
    
            if (
                empty($assignedRoles) &&
                !empty($user->role)
            ) {
                $assignedRoles = [$user->role];
            }
    
    
            // ============================================================
            // 13. Response
            //
            // Employee ID + Warehouse are passed directly from ERPNext.
            // ============================================================
    
            return [
                'token' => $token,
    
                'user' => $this->formatUserResponse(
                    $user,
                    $assignedRoles,
                    $erpnextEmpID,
                    $erpnextActiveWarehouse
                ),
            ];
    
    
        } catch (Exception $e) {
    
            Log::info(
                'ERPNext auth failed, checking local database fallback for: '
                . $identifier
            );
    
    
            // ============================================================
            // LOCAL DATABASE FALLBACK
            // ============================================================
    
            $user = User::where('email', $identifier)
                ->orWhere('username', $identifier)
                ->first();
    
    
            if (
                $user &&
                Hash::check($password, $user->password)
            ) {
    
                if ($user->status !== 'active') {
                    throw new Exception(
                        'Your account is inactive.'
                    );
                }
    
    
                // --------------------------------------------------------
                // Get ERPNext credentials
                // --------------------------------------------------------
    
                $userApiKey = Schema::hasColumn(
                    'users',
                    'erpnext_api_key'
                )
                    ? $user->erpnext_api_key
                    : null;
    
                $userApiSecret = Schema::hasColumn(
                    'users',
                    'erpnext_api_secret'
                )
                    ? $user->erpnext_api_secret
                    : null;
    
                $userToken = Schema::hasColumn(
                    'users',
                    'erpnext_token'
                )
                    ? $user->erpnext_token
                    : null;
    
    
                // --------------------------------------------------------
                // Extract API key / secret from token if needed
                // --------------------------------------------------------
    
                if (
                    (!$userApiKey || !$userApiSecret) &&
                    !empty($userToken) &&
                    str_starts_with($userToken, 'token ')
                ) {
    
                    $parts = explode(
                        ':',
                        str_replace('token ', '', $userToken)
                    );
    
                    if (count($parts) === 2) {
    
                        $userApiKey = $userApiKey ?: $parts[0];
                        $userApiSecret = $userApiSecret ?: $parts[1];
    
                        $fallbackUpdate = [];
    
                        if (
                            Schema::hasColumn(
                                'users',
                                'erpnext_api_key'
                            )
                        ) {
                            $fallbackUpdate['erpnext_api_key']
                                = $userApiKey;
                        }
    
                        if (
                            Schema::hasColumn(
                                'users',
                                'erpnext_api_secret'
                            )
                        ) {
                            $fallbackUpdate['erpnext_api_secret']
                                = $userApiSecret;
                        }
    
                        if (!empty($fallbackUpdate)) {
                            $user->update($fallbackUpdate);
                        }
                    }
                }
    
    
                // --------------------------------------------------------
                // New Sanctum token
                // --------------------------------------------------------
    
                $user->tokens()->delete();
    
                $token = $user
                    ->createToken('auth_token')
                    ->plainTextToken;
    
    
                // --------------------------------------------------------
                // Roles
                // --------------------------------------------------------
    
                $user->load('roles');
    
                $roles = $user
                    ->roles
                    ->pluck('name')
                    ->toArray();
    
                if (
                    empty($roles) &&
                    !empty($user->role)
                ) {
                    $roles = [$user->role];
                }
    
    
                // --------------------------------------------------------
                // Local fallback has no fresh ERPNext employee/warehouse
                // --------------------------------------------------------
    
                return [
                    'token' => $token,
    
                    'user' => $this->formatUserResponse(
                        $user,
                        $roles,
                        null,
                        null
                    ),
                ];
            }
    
    
            throw new Exception(
                $e->getMessage()
                    ?: 'Invalid ERPNext credentials'
            );
        }
    }
    /**
     * Format user data for login API response.
     *
     * Employee ID and Active Warehouse come directly from ERPNext
     * and are NOT read from or saved to the local users table.
     *
     * @param User $user
     * @param array $roles
     * @param string|null $employeeId
     * @param string|null $activeWarehouse
     * @return array
     */
    protected function formatUserResponse(
        User $user,
        array $roles,
        ?string $employeeId = null,
        ?string $activeWarehouse = null
    ): array {
        return [
            'id' => $user->id,
    
            'name' => $user->name,
    
            'email' => $user->email,
    
            'username' => $user->username,
    
            'erpnext_user_id' => $user->erpnext_user_id,
    
            'erpnext_token' => $user->erpnext_token,
    
            'erpnext_synced_at' => $user->erpnext_synced_at
                ? $user->erpnext_synced_at->toISOString()
                : null,
    
            // 'phone' => $user->phone,
    
            // 'email_verified_at' => $user->email_verified_at
            //     ? $user->email_verified_at->toISOString()
            //     : null,
    
            'status' => $user->status,
    
            // 'created_at' => $user->created_at
            //     ? $user->created_at->toISOString()
            //     : null,
    
            // 'updated_at' => $user->updated_at
            //     ? $user->updated_at->toISOString()
            //     : null,
    
            //'roles' => array_values($roles),
    
            // ============================================================
            // ERPNext User Details
            // These values come directly from ERPNext login response.
            // They are NOT stored in users table.
            // ============================================================
    
            'user_details' => [
                'user_id' => $user->email,
    
                'user_name' => $user->name,
    
                'status' => $user->status,
    
                'employee_id' => $employeeId,
    
                'active_warehouse' => $activeWarehouse,
    
                'roles' => array_values($roles),
            ],
        ];
    }


    /**
     * Logout a user (revoke current token).
     *
     * @param User $user
     * @return void
     */
    public function logout(User $user): void
    {
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }
    }

    /**
     * Change user password.
     *
     * @param User $user
     * @param string $newPassword
     * @return void
     */
    public function changePassword(User $user, string $newPassword): void
    {
        $user->password = Hash::make($newPassword);
        $user->save();

        // Revoke all tokens so they have to login again
        $user->tokens()->delete();
    }
}
