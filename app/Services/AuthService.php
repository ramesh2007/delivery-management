<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Exception;

class AuthService
{
    protected $userRepository;

    /**
     * AuthService constructor.
     *
     * @param UserRepositoryInterface $userRepository
     */
    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Attempt to authenticate a user.
     *
     * @param array $credentials
     * @return array
     * @throws Exception
     */
    public function login(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new Exception('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new Exception('Your account is inactive.');
        }

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
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
        $user->currentAccessToken()->delete();
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
