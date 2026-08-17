<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

            if (!$user) {
                $user = User::create([
                    'name' => $userName ?? $identifier,
                    'email' => $userEmail ?: ($erpnextUsername ? $erpnextUsername . '@erpnext.local' : $identifier . '@erpnext.local'),
                    'username' => $erpnextUsername ?: $identifier,
                    'password' => Hash::make($password),
                    'status' => 'active',
                    'role' => $erpnextRoles[0] ?? 'User',
                    'erpnext_user_id' => $erpnextUserId,
                    'erpnext_api_key' => $erpnextApiKey,
                    'erpnext_api_secret' => $erpnextApiSecret,
                    'erpnext_token' => $erpnextToken,
                    'erpnext_synced_at' => now(),
                ]);
            } else {
                $user->update([
                    'name' => $userName ?: $user->name,
                    'username' => $erpnextUsername ?: ($user->username ?: $identifier),
                    'password' => Hash::make($password), // Keep local password hash updated for offline fallback
                    'status' => 'active',
                    'role' => $erpnextRoles[0] ?? ($user->role ?? 'User'),
                    'erpnext_user_id' => $erpnextUserId ?: $user->erpnext_user_id,
                    'erpnext_api_key' => $erpnextApiKey ?: $user->erpnext_api_key,
                    'erpnext_api_secret' => $erpnextApiSecret ?: $user->erpnext_api_secret,
                    'erpnext_token' => $erpnextToken ?: $user->erpnext_token,
                    'erpnext_synced_at' => now(),
                ]);
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

            // Generate Sanctum token for OMS session
            $token = $user->createToken('auth_token')->plainTextToken;

            $user->load('roles');
            $assignedRoles = $user->roles->pluck('name')->toArray();
            if (empty($assignedRoles) && !empty($user->role)) {
                $assignedRoles = [$user->role];
            }
            $primaryRole = $assignedRoles[0] ?? null;

            return [
                'token' => $token,
                // 'api_key' => $user->erpnext_api_key,
                // 'api_secret' => $user->erpnext_api_secret,
                // 'user_creds' => [
                //     'api_key' => $user->erpnext_api_key,
                //     'api_secret' => $user->erpnext_api_secret,
                // ],
                // 'erpnext_api_key' => $user->erpnext_api_key,
                // 'erpnext_api_secret' => $user->erpnext_api_secret,
                'erpnext_token' => $user->erpnext_token,
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

                $token = $user->createToken('auth_token')->plainTextToken;
                $user->load('roles');
                $roles = $user->roles->pluck('name')->toArray();
                $primaryRole = $roles[0] ?? $user->role ?? null;

                return [
                    'token' => $token,
                    'api_key' => $user->erpnext_api_key,
                    'api_secret' => $user->erpnext_api_secret,
                    'user_creds' => [
                        'api_key' => $user->erpnext_api_key,
                        'api_secret' => $user->erpnext_api_secret,
                    ],
                    'erpnext_api_key' => $user->erpnext_api_key,
                    'erpnext_api_secret' => $user->erpnext_api_secret,
                    'erpnext_token' => $user->erpnext_token,
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
