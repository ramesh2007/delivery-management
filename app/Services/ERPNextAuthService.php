<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ERPNextAuthService
{
    protected string $baseUrl;
    protected string $loginEndpoint;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.erpnext.base_url', 'https://halamama.rakonex.cc'), '/');
        $this->loginEndpoint = '/' . ltrim(config('services.erpnext.login_endpoint', '/api/method/warehouse_management.api.login.mobile_login'), '/');
    }

    /**
     * Authenticate user credentials against ERPNext mobile login API endpoint.
     *
     * @param string $username (email or username)
     * @param string $password
     * @return array
     * @throws Exception
     */
    public function authenticate(string $username, string $password): array
    {
        $url = $this->baseUrl . $this->loginEndpoint;

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post($url, [
                    'username' => $username,
                    'password' => $password,
                ]);

            if ($response->failed()) {
                Log::warning('ERPNext Auth Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $json = $response->json();
                $message = $json['message'] ?? $json['exception'] ?? 'Invalid ERPNext credentials';

                throw new Exception(is_string($message) ? $message : 'Invalid ERPNext credentials');
            }

            $responseData = $response->json();

            // Handle standard ERPNext wrapper format {"message": {...}} or direct response
            $data = isset($responseData['message']) && is_array($responseData['message'])
                ? $responseData['message']
                : $responseData;

            // Normalize user details
            $userEmail = $data['email'] ?? $data['user'] ?? $data['user_id'] ?? (filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null);
            $userName = $data['full_name'] ?? $data['name'] ?? $data['user_name'] ?? ($userEmail ? explode('@', $userEmail)[0] : $username);
            $erpnextUserId = $data['user_id'] ?? $data['user'] ?? $userEmail ?? $username;
            $erpnextUsername = $data['username'] ?? (filter_var($username, FILTER_VALIDATE_EMAIL) ? explode('@', $username)[0] : $username);
            $userCreds = (isset($data['user_creds']) && is_array($data['user_creds'])) ? $data['user_creds'] : [];
            $erpnextApiKey = $data['api_key'] ?? $data['key'] ?? ($userCreds['api_key'] ?? null);
            $erpnextApiSecret = $data['api_secret'] ?? $data['secret'] ?? ($userCreds['api_secret'] ?? null);
            $erpnextToken = $data['token'] ?? $data['sid'] ?? ($erpnextApiKey && $erpnextApiSecret ? "token {$erpnextApiKey}:{$erpnextApiSecret}" : null);

            // Extract roles
            $roles = [];
            if (isset($data['roles']) && is_array($data['roles'])) {
                $roles = $data['roles'];
            } elseif (isset($data['role'])) {
                $roles = is_array($data['role']) ? $data['role'] : [$data['role']];
            } elseif (isset($data['user_roles']) && is_array($data['user_roles'])) {
                $roles = $data['user_roles'];
            }

            return [
                'success' => true,
                'email' => $userEmail,
                'name' => $userName,
                'username' => $erpnextUsername,
                'erpnext_user_id' => $erpnextUserId,
                'erpnext_api_key' => $erpnextApiKey,
                'erpnext_api_secret' => $erpnextApiSecret,
                'erpnext_token' => $erpnextToken,
                'roles' => array_values(array_filter($roles)),
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('ERPNext Auth Exception: ' . $e->getMessage());
            throw $e;
        }
    }
}
