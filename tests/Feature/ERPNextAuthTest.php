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

    public function test_subsequent_login_updates_erpnext_token_and_api_keys()
    {
        Http::fake([
            'https://halamama.rakonex.cc/api/method/warehouse_management.api.login.mobile_login' => Http::sequence()
                ->push([
                    'message' => [
                        'user_id' => 'repeatuser@example.com',
                        'full_name' => 'Repeat User',
                        'email' => 'repeatuser@example.com',
                        'username' => 'repeatuser',
                        'api_key' => 'old_key_111',
                        'api_secret' => 'old_secret_222',
                        'token' => 'token old_key_111:old_secret_222',
                        'roles' => ['Picker'],
                    ]
                ], 200)
                ->push([
                    'message' => [
                        'user_id' => 'repeatuser@example.com',
                        'full_name' => 'Repeat User',
                        'email' => 'repeatuser@example.com',
                        'username' => 'repeatuser',
                        'api_key' => 'new_key_999',
                        'api_secret' => 'new_secret_888',
                        'token' => 'token new_key_999:new_secret_888',
                        'roles' => ['Picker'],
                    ]
                ], 200),
        ]);

        // 1. Initial Login
        $this->postJson('/api/login', [
            'username' => 'repeatuser@example.com',
            'password' => 'password123',
        ]);

        // 2. Second Login with NEW credentials returned from ERPNext
        $response = $this->postJson('/api/login', [
            'username' => 'repeatuser@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'erpnext_api_key' => 'new_key_999',
                    'erpnext_api_secret' => 'new_secret_888',
                    'erpnext_token' => 'token new_key_999:new_secret_888',
                    'api_key' => 'new_key_999',
                    'api_secret' => 'new_secret_888',
                    'user_creds' => [
                        'api_key' => 'new_key_999',
                        'api_secret' => 'new_secret_888',
                        'token' => 'token new_key_999:new_secret_888',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'repeatuser@example.com',
            'erpnext_api_key' => 'new_key_999',
            'erpnext_api_secret' => 'new_secret_888',
            'erpnext_token' => 'token new_key_999:new_secret_888',
        ]);
    }

    public function test_fresh_api_secret_reconstructs_erpnext_token_header()
    {
        // Simulate ERPNext returning a fresh secret 59a1c496f342415
        Http::fake([
            'https://halamama.rakonex.cc/api/method/warehouse_management.api.login.mobile_login' => Http::response([
                'message' => [
                    'user_id' => 'freshsecret@example.com',
                    'full_name' => 'Secret User',
                    'email' => 'freshsecret@example.com',
                    'username' => 'freshsecret',
                    'api_key' => 'be50da67694f729',
                    'api_secret' => '59a1c496f342415',
                    'token' => 'token be50da67694f729:4410f13b3b08483', // Legacy token string from ERPNext payload
                    'roles' => ['Picker'],
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'freshsecret@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'erpnext_api_key' => 'be50da67694f729',
                    'erpnext_api_secret' => '59a1c496f342415',
                    'erpnext_token' => 'token be50da67694f729:59a1c496f342415',
                    'user_creds' => [
                        'api_key' => 'be50da67694f729',
                        'api_secret' => '59a1c496f342415',
                        'token' => 'token be50da67694f729:59a1c496f342415',
                    ],
                ],
            ]);
    }

    public function test_login_parses_user_details_and_user_creds_nested_structure()
    {
        // Sample response matching real ERPNext mobile login API output
        Http::fake([
            'https://halamama.rakonex.cc/api/method/warehouse_management.api.login.mobile_login' => Http::response([
                'message' => [
                    'success' => true,
                    'message' => 'Login successful',
                    'user_details' => [
                        'user_id' => 'picker@example.com',
                        'user_name' => 'Picker',
                        'status' => 'active',
                        'roles' => ['Picker'],
                    ],
                    'user_creds' => [
                        'api_key' => 'be50da67694f729',
                        'api_secret' => '52a6d31d964dbd2',
                    ],
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'picker@example.com',
            'password' => 'picker123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'api_key' => 'be50da67694f729',
                    'api_secret' => '52a6d31d964dbd2',
                    'erpnext_api_key' => 'be50da67694f729',
                    'erpnext_api_secret' => '52a6d31d964dbd2',
                    'erpnext_token' => 'token be50da67694f729:52a6d31d964dbd2',
                    'user_creds' => [
                        'api_key' => 'be50da67694f729',
                        'api_secret' => '52a6d31d964dbd2',
                        'token' => 'token be50da67694f729:52a6d31d964dbd2',
                    ],
                    'user' => [
                        'email' => 'picker@example.com',
                        'name' => 'Picker',
                    ],
                    'roles' => ['Picker'],
                    'role' => 'Picker',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'picker@example.com',
            'erpnext_api_key' => 'be50da67694f729',
            'erpnext_api_secret' => '52a6d31d964dbd2',
            'erpnext_token' => 'token be50da67694f729:52a6d31d964dbd2',
        ]);
    }
}
