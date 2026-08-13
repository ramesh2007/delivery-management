<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;

class RolePermissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user who will be used to make authorized API requests
        $this->adminUser = User::factory()->create([
            'email' => 'admin@example.com',
        ]);
        
        $adminRole = Role::create([
            'name' => 'admin',
            'description' => 'Administrator'
        ]);
        
        $this->adminUser->roles()->attach($adminRole);
        $this->adminToken = $this->adminUser->createToken('test_token')->plainTextToken;
    }

    /**
     * Test Role CRUD.
     */
    public function test_role_crud(): void
    {
        // 1. Create Role
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/roles', [
                'name' => 'editor',
                'description' => 'Editor Role',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'editor');
        $this->assertDatabaseHas('roles', ['name' => 'editor']);

        $roleId = $response->json('data.id');

        // 2. Read Role
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson("/api/roles/{$roleId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'editor');

        // 3. Update Role
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/api/roles/{$roleId}", [
                'description' => 'Updated Editor Role Description',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.description', 'Updated Editor Role Description');

        // 4. Delete Role
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->deleteJson("/api/roles/{$roleId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    /**
     * Test Permission CRUD.
     */
    public function test_permission_crud(): void
    {
        // 1. Create Permission
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/permissions', [
                'name' => 'publish-articles',
                'description' => 'Ability to publish articles',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'publish-articles');
        $this->assertDatabaseHas('permissions', ['name' => 'publish-articles']);

        $permId = $response->json('data.id');

        // 2. Read Permission
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->getJson("/api/permissions/{$permId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'publish-articles');

        // 3. Update Permission
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/api/permissions/{$permId}", [
                'description' => 'Updated publish articles permission',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.description', 'Updated publish articles permission');

        // 4. Delete Permission
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->deleteJson("/api/permissions/{$permId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('permissions', ['id' => $permId]);
    }

    /**
     * Test creating a role and assigning permissions in one call.
     */
    public function test_create_role_with_permissions(): void
    {
        $perm1 = Permission::create(['name' => 'edit-posts']);
        $perm2 = Permission::create(['name' => 'delete-posts']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson('/api/roles', [
                'name' => 'editor',
                'description' => 'Can edit and delete posts',
                'permissions' => [$perm1->id, 'delete-posts'], // Support both ID and name
            ]);

        $response->assertStatus(201);
        
        $role = Role::where('name', 'editor')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->permissions()->where('name', 'edit-posts')->exists());
        $this->assertTrue($role->permissions()->where('name', 'delete-posts')->exists());
    }

    /**
     * Test assigning permissions to a role.
     */
    public function test_assign_permissions_to_role(): void
    {
        $role = Role::create(['name' => 'editor']);
        $perm = Permission::create(['name' => 'edit-posts']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/roles/{$role->id}/permissions", [
                'permissions' => [$perm->id]
            ]);

        $response->assertStatus(200);
        $this->assertTrue($role->permissions()->where('name', 'edit-posts')->exists());
    }

    /**
     * Test assigning roles to a user.
     */
    public function test_assign_roles_to_user(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'driver']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->postJson("/api/users/{$user->id}/roles", [
                'roles' => [$role->id]
            ]);

        $response->assertStatus(200);
        $this->assertTrue($user->hasRole('driver'));
    }

    /**
     * Test Authorization Middleware for permissions.
     */
    public function test_permission_middleware(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('user_token')->plainTextToken;

        // 1. Unassigned permission -> expect 403
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/test-permission');
        $response->assertStatus(403);

        // 2. Assign role with permission
        $role = Role::create(['name' => 'editor-role']);
        $perm = Permission::create(['name' => 'edit-users']);
        $role->permissions()->attach($perm);
        $user->roles()->attach($role);

        // 3. User now has permission -> expect 200
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/test-permission');
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Access granted. You have the edit-users permission!'
        ]);
    }
}
