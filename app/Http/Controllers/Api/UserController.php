<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Create a new user with a role and issue a token.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        // Create the user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Attach the role
        $role = Role::where('name', $validated['role'])->first();
        $user->roles()->attach($role);

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Load the roles relationship to include in response
        $user->load('roles');

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user,
            'token_type' => 'Bearer',
            'access_token' => $token,
        ], 201);
    }
}
