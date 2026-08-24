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
        $password = $credentials['password'];

        if (empty($identifier) || empty($password)) {
            throw new Exception('Username/Email and Password are required');
        }

        try {
            // 1. Authenticate with ERPNext mobile login API
            $erpResult = $this->erpnextAuthService->authenticate($identifier, $password);

            $userEmail = $erpResult['email'];
            $userName = $erpResult['name'];
            $erpnextUsername = $erpResult['username'];
            $erpnextUserId = $erpResult['erpnext_user_id'];
            $erpnextApiKey = $erpResult['erpnext_api_key'];
            $erpnextApiSecret = $erpResult['erpnext_api_secret'];
            $erpnextToken = $erpResult['erpnext_token'];
            $erpnextRoles = $erpResult['roles'];

            // 2. Find or create local User record
            $user = null;
            if ($userEmail) {
                $user = User::where('email', $userEmail)->first();
            }
            if (!$user && $erpnextUsername) {
                $user = User::where('username', $erpnextUsername)->first();
            }
            if (!$user && $identifier) {
                $user = User::where('username', $identifier)->orWhere('email', $identifier)->first();
            }

            // If user's local record has an erpnext_token formatted as 'token KEY:SECRET' but missing api_key/api_secret:
            $dbApiKey = $user ? $user->erpnext_api_key : null;
            $dbApiSecret = $user ? $user->erpnext_api_secret : null;

            if (($user && (!$dbApiKey || !$dbApiSecret)) && !empty($user->erpnext_token) && str_starts_with($user->erpnext_token, 'token ')) {
                $parts = explode(':', str_replace('token ', '', $user->erpnext_token));
                if (count($parts) === 2) {
                    $dbApiKey = $dbApiKey ?: $parts[0];
                    $dbApiSecret = $dbApiSecret ?: $parts[1];
                }
            }

            // Determine effective ERPNext credentials & token
            $finalApiKey = !empty($erpnextApiKey) ? $erpnextApiKey : $dbApiKey;
            $finalApiSecret = !empty($erpnextApiSecret) ? $erpnextApiSecret : $dbApiSecret;

            // If new API key and secret exist, compute the updated token string directly
            if (!empty($finalApiKey) && !empty($finalApiSecret)) {
                $finalToken = "token {$finalApiKey}:{$finalApiSecret}";
            } elseif (!empty($erpnextToken)) {
                $finalToken = $erpnextToken;
            } else {
                $finalToken = $user ? $user->erpnext_token : null;
            }

            $userPayload = [
                'name' => $userName ?: ($user ? $user->name : $identifier),
                'email' => $userEmail ?: ($user ? $user->email : ($erpnextUsername ? $erpnextUsername . '@erpnext.local' : $identifier . '@erpnext.local')),
                'username' => $erpnextUsername ?: ($user ? $user->username : $identifier),
                'password' => Hash::make($password),
                'status' => 'active',
                'role' => $erpnextRoles[0] ?? ($user ? $user->role : 'User'),
                'erpnext_user_id' => $erpnextUserId ?: ($user ? $user->erpnext_user_id : null),
                'erpnext_api_key' => $finalApiKey,
                'erpnext_api_secret' => $finalApiSecret,
                'erpnext_token' => $finalToken,
                'erpnext_synced_at' => now(),
            ];

            // Safely filter attributes based on columns existing in the users table
            $validColumns = array_filter(array_keys($userPayload), fn($col) => Schema::hasColumn('users', $col));
            $userPayload = array_intersect_key($userPayload, array_flip($validColumns));

            if (!$user) {
                $user = User::create($userPayload);
            } else {
                $user->update($userPayload);
            }

            // 3. Sync Roles
            if (!empty($erpnextRoles)) {
                $roleIds = [];
                foreach ($erpnextRoles as $roleName) {
                    $roleModel = Role::firstOrCreate(['name' => $roleName]);
                    $roleIds[] = $roleModel->id;
                }
                $user->roles()->sync($roleIds);
            }

            // Revoke old Sanctum tokens so user gets a fresh token each login
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            $user->load('roles');
            $assignedRoles = $user->roles->pluck('name')->toArray();
            if (empty($assignedRoles) && !empty($user->role)) {
                $assignedRoles = [$user->role];
            }
            $primaryRole = $assignedRoles[0] ?? null;

            $resApiKey = Schema::hasColumn('users', 'erpnext_api_key') ? $user->erpnext_api_key : $finalApiKey;
            $resApiSecret = Schema::hasColumn('users', 'erpnext_api_secret') ? $user->erpnext_api_secret : $finalApiSecret;
            $resToken = Schema::hasColumn('users', 'erpnext_token') ? $user->erpnext_token : $finalToken;

            return [
                'token' => $token,
                'api_key' => $resApiKey,
                'api_secret' => $resApiSecret,
                'erpnext_api_key' => $resApiKey,
                'erpnext_api_secret' => $resApiSecret,
                'erpnext_token' => $resToken,
                'user_creds' => [
                    'api_key' => $resApiKey,
                    'api_secret' => $resApiSecret,
                    'token' => $resToken,
                ],
                'user' => $user,
                'roles' => $assignedRoles,
                'role' => $primaryRole,
            ];
        } catch (Exception $e) {
            Log::info('ERPNext auth failed, checking local database fallback for: ' . $identifier);

            // Fallback: check local database authentication if user exists
            $user = User::where('email', $identifier)
                ->orWhere('username', $identifier)
                ->first();

            if ($user && Hash::check($password, $user->password)) {
                if ($user->status !== 'active') {
                    throw new Exception('Your account is inactive.');
                }

                $userApiKey = Schema::hasColumn('users', 'erpnext_api_key') ? $user->erpnext_api_key : null;
                $userApiSecret = Schema::hasColumn('users', 'erpnext_api_secret') ? $user->erpnext_api_secret : null;
                $userToken = Schema::hasColumn('users', 'erpnext_token') ? $user->erpnext_token : null;

                if ((!$userApiKey || !$userApiSecret) && !empty($userToken) && str_starts_with($userToken, 'token ')) {
                    $parts = explode(':', str_replace('token ', '', $userToken));
                    if (count($parts) === 2) {
                        $userApiKey = $userApiKey ?: $parts[0];
                        $userApiSecret = $userApiSecret ?: $parts[1];

                        $fallbackUpdate = [];
                        if (Schema::hasColumn('users', 'erpnext_api_key')) {
                            $fallbackUpdate['erpnext_api_key'] = $userApiKey;
                        }
                        if (Schema::hasColumn('users', 'erpnext_api_secret')) {
                            $fallbackUpdate['erpnext_api_secret'] = $userApiSecret;
                        }
                        if (!empty($fallbackUpdate)) {
                            $user->update($fallbackUpdate);
                        }
                    }
                }

                $user->tokens()->delete();
                $token = $user->createToken('auth_token')->plainTextToken;
                $user->load('roles');
                $roles = $user->roles->pluck('name')->toArray();
                if (empty($roles) && !empty($user->role)) {
                    $roles = [$user->role];
                }
                $primaryRole = $roles[0] ?? $user->role ?? null;

                return [
                    'token' => $token,
                    'api_key' => $userApiKey,
                    'api_secret' => $userApiSecret,
                    'erpnext_api_key' => $userApiKey,
                    'erpnext_api_secret' => $userApiSecret,
                    'erpnext_token' => $user->erpnext_token,
                    'user_creds' => [
                        'api_key' => $userApiKey,
                        'api_secret' => $userApiSecret,
                        'token' => $user->erpnext_token,
                    ],
                    'user' => $user,
                    'roles' => $roles,
                    'role' => $primaryRole,
                ];
            }

            throw new Exception($e->getMessage() ?: 'Invalid ERPNext credentials');
        }
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
