<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;
use App\Models\Role;
use Database\Seeders\RoleSeeder;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed the roles
        $this->seed(RoleSeeder::class);
    }

    /**
     * Test creating a user with a role.
     */
    public function test_can_create_user_with_role(): void
    {
        $response = $this->postJson('/api/users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'securepassword123',
            'role' => 'admin',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'user' => [
                'id',
                'name',
                'email',
                'roles',
            ],
            'token_type',
            'access_token',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('driver'));
    }

    /**
     * Test creating a user with an invalid role fails.
     */
    public function test_cannot_create_user_with_invalid_role(): void
    {
        $response = $this->postJson('/api/users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'securepassword123',
            'role' => 'invalid_role',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    }

    /**
     * Test guest cannot access the test role route.
     */
    public function test_guest_cannot_access_test_role(): void
    {
        $response = $this->getJson('/api/test-role');
        $response->assertStatus(401);
    }

    /**
     * Test non-admin cannot access the admin test role route.
     */
    public function test_non_admin_cannot_access_test_role(): void
    {
        $driverUser = User::factory()->create();
        $driverRole = Role::where('name', 'driver')->first();
        $driverUser->roles()->attach($driverRole);

        $driverToken = $driverUser->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $driverToken)
            ->getJson('/api/test-role');
        
        $response->assertStatus(403);
    }

    /**
     * Test admin can access the admin test role route.
     */
    public function test_admin_can_access_test_role(): void
    {
        $adminUser = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        $adminUser->roles()->attach($adminRole);

        $adminToken = $adminUser->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/test-role');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Access granted. You have the admin role!',
        ]);
    }
}
