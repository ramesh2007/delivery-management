<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ERPNextAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_via_username_with_erpnext_authentication_and_sync_roles()
    {
        // Mock ERPNext Mobile Login API endpoint
        Http::fake([
            'https://halamama.rakonex.cc/api/method/warehouse_management.api.login.mobile_login' => Http::response([
                'message' => [
                    'user_id' => 'apitestuser@example.com',
                    'full_name' => 'API Test User',
                    'email' => 'apitestuser@example.com',
                    'username' => 'apitestuser',
                    'api_key' => 'frappe_key_123',
                    'api_secret' => 'frappe_secret_456',
                    'token' => 'token frappe_key_123:frappe_secret_456',
                    'roles' => ['Warehouse Manager', 'Picker'],
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'apitestuser@example.com',
            'password' => 'apitestuser@example#123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'erpnext_api_key' => 'frappe_key_123',
                    'erpnext_api_secret' => 'frappe_secret_456',
                    'erpnext_token' => 'token frappe_key_123:frappe_secret_456',
                    'user' => [
                        'email' => 'apitestuser@example.com',
                        'name' => 'API Test User',
                    ],
                    'roles' => ['Warehouse Manager', 'Picker'],
                    'role' => 'Warehouse Manager',
                ],
            ]);

        $this->assertNotNull($response->json('data.token'));

        $this->assertDatabaseHas('users', [
            'email' => 'apitestuser@example.com',
            'username' => 'apitestuser',
            'erpnext_user_id' => 'apitestuser@example.com',
            'erpnext_api_key' => 'frappe_key_123',
            'erpnext_api_secret' => 'frappe_secret_456',
        ]);

        $user = User::where('email', 'apitestuser@example.com')->first();
        $this->assertTrue($user->hasRole('Warehouse Manager'));
        $this->assertTrue($user->hasRole('Picker'));
    }

    public function test_user_login_fails_when_erpnext_returns_invalid_credentials()
    {
        Http::fake([
            'https://halamama.rakonex.cc/api/method/warehouse_management.api.login.mobile_login' => Http::response([
                'message' => 'Invalid Login Credentials'
            ], 401),
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'wronguser@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_offline_fallback_allows_login_for_existing_local_user_if_erpnext_down()
    {
        Http::fake([
            'https://halamama.rakonex.cc/*' => Http::response([], 500),
        ]);

        $user = User::factory()->create([
            'email' => 'offlineuser@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'offlineuser@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'offlineuser@example.com',
                    ],
                ],
            ]);
    }
}
