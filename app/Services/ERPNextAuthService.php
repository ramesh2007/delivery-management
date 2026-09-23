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
    
                $message = $json['message']
                    ?? $json['exception']
                    ?? 'Invalid ERPNext credentials';
    
                throw new Exception(
                    is_string($message)
                        ? $message
                        : 'Invalid ERPNext credentials'
                );
            }
    
            $responseData = $response->json();
    
            /*
            |--------------------------------------------------------------------------
            | Handle ERPNext wrapper
            |--------------------------------------------------------------------------
            |
            | ERPNext response:
            |
            | {
            |     "message": {
            |         "success": true,
            |         "message": "Login successful",
            |         "user_details": {...},
            |         "user_creds": {...}
            |     }
            | }
            |
            */
    
            $data = isset($responseData['message'])
                && is_array($responseData['message'])
                ? $responseData['message']
                : $responseData;
    
    
            /*
            |--------------------------------------------------------------------------
            | Check ERPNext login success
            |--------------------------------------------------------------------------
            */
    
            if (
                isset($data['success']) &&
                (
                    $data['success'] === false ||
                    $data['success'] === 0 ||
                    $data['success'] === 'false'
                )
            ) {
                $errorMessage = $data['message']
                    ?? 'Invalid ERPNext credentials';
    
                throw new Exception(
                    is_string($errorMessage)
                        ? $errorMessage
                        : 'Invalid ERPNext credentials'
                );
            }
    
    
            /*
            |--------------------------------------------------------------------------
            | Extract user_details
            |--------------------------------------------------------------------------
            */
    
            $userDetails = (
                isset($data['user_details']) &&
                is_array($data['user_details'])
            )
                ? $data['user_details']
                : [];
    
    
            /*
            |--------------------------------------------------------------------------
            | Extract user_creds
            |--------------------------------------------------------------------------
            */
    
            $userCreds = (
                isset($data['user_creds']) &&
                is_array($data['user_creds'])
            )
                ? $data['user_creds']
                : [];
    
    
            /*
            |--------------------------------------------------------------------------
            | Support nested data.user_creds if ERPNext changes response format
            |--------------------------------------------------------------------------
            */
    
            if (
                empty($userCreds) &&
                isset($data['data']['user_creds']) &&
                is_array($data['data']['user_creds'])
            ) {
                $userCreds = $data['data']['user_creds'];
            }
    
    
            /*
            |--------------------------------------------------------------------------
            | Normalize user email
            |--------------------------------------------------------------------------
            */
    
            $userEmail = $userDetails['user_id']
                ?? $userDetails['email']
                ?? $data['email']
                ?? $data['user']
                ?? $data['user_id']
                ?? (
                    filter_var($username, FILTER_VALIDATE_EMAIL)
                        ? $username
                        : null
                );
    
    
            /*
            |--------------------------------------------------------------------------
            | Normalize user name
            |--------------------------------------------------------------------------
            */
    
            $userName = $userDetails['user_name']
                ?? $userDetails['full_name']
                ?? $userDetails['name']
                ?? $data['full_name']
                ?? $data['name']
                ?? $data['user_name']
                ?? (
                    $userEmail
                        ? explode('@', $userEmail)[0]
                        : $username
                );
    
    
            /*
            |--------------------------------------------------------------------------
            | ERPNext User ID
            |--------------------------------------------------------------------------
            */
    
            $erpnextUserId = $userDetails['user_id']
                ?? $data['user_id']
                ?? $data['user']
                ?? $userEmail
                ?? $username;
    
    
            /*
            |--------------------------------------------------------------------------
            | ERPNext Username
            |--------------------------------------------------------------------------
            */
    
            $erpnextUsername = $userDetails['username']
                ?? $data['username']
                ?? (
                    filter_var($username, FILTER_VALIDATE_EMAIL)
                        ? explode('@', $username)[0]
                        : $username
                );
    
    
            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Extract Employee ID
            |--------------------------------------------------------------------------
            */
    
            $erpnextEmployeeId = $userDetails['employee_id']
                ?? $data['employee_id']
                ?? null;
    
    
            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Extract Active Warehouse
            |--------------------------------------------------------------------------
            */
    
            $erpnextActiveWarehouse = $userDetails['active_warehouse']
                ?? $data['active_warehouse']
                ?? null;
    
    
            /*
            |--------------------------------------------------------------------------
            | Normalize ERPNext API credentials
            |--------------------------------------------------------------------------
            */
    
            $erpnextApiKey = $userCreds['api_key']
                ?? $userCreds['key']
                ?? $data['api_key']
                ?? $data['key']
                ?? null;
    
    
            $erpnextApiSecret = $userCreds['api_secret']
                ?? $userCreds['secret']
                ?? $data['api_secret']
                ?? $data['secret']
                ?? null;
    
    
            /*
            |--------------------------------------------------------------------------
            | Build ERPNext token
            |--------------------------------------------------------------------------
            */
    
            if (
                !empty($erpnextApiKey) &&
                !empty($erpnextApiSecret)
            ) {
                $erpnextToken =
                    "token {$erpnextApiKey}:{$erpnextApiSecret}";
            } else {
                $erpnextToken =
                    $userCreds['token']
                    ?? $data['token']
                    ?? $data['sid']
                    ?? null;
            }
    
    
            /*
            |--------------------------------------------------------------------------
            | Extract roles
            |--------------------------------------------------------------------------
            */
    
            $roles = [];
    
            if (
                !empty($userDetails['roles']) &&
                is_array($userDetails['roles'])
            ) {
                $roles = $userDetails['roles'];
    
            } elseif (
                !empty($data['roles']) &&
                is_array($data['roles'])
            ) {
                $roles = $data['roles'];
    
            } elseif (
                isset($userDetails['role'])
            ) {
                $roles = is_array($userDetails['role'])
                    ? $userDetails['role']
                    : [$userDetails['role']];
    
            } elseif (
                isset($data['role'])
            ) {
                $roles = is_array($data['role'])
                    ? $data['role']
                    : [$data['role']];
    
            } elseif (
                isset($data['user_roles']) &&
                is_array($data['user_roles'])
            ) {
                $roles = $data['user_roles'];
            }
    
    
            /*
            |--------------------------------------------------------------------------
            | Clean roles
            |--------------------------------------------------------------------------
            */
    
            $roles = array_values(
                array_filter(
                    $roles,
                    fn($role) => !empty($role)
                )
            );
    
    
            /*
            |--------------------------------------------------------------------------
            | Debug logging - useful for confirming values
            |--------------------------------------------------------------------------
            */
    
            Log::info('ERPNext Authentication Parsed', [
                'email' => $userEmail,
                'username' => $erpnextUsername,
                'employee_id' => $erpnextEmployeeId,
                'active_warehouse' => $erpnextActiveWarehouse,
                'roles' => $roles,
                'api_key' => $erpnextApiKey,
                'has_api_secret' => !empty($erpnextApiSecret),
            ]);
    
    
            /*
            |--------------------------------------------------------------------------
            | Return normalized ERPNext data
            |--------------------------------------------------------------------------
            */
    
            return [
                'success' => true,
    
                'email' => $userEmail,
    
                'name' => $userName,
    
                'username' => $erpnextUsername,
    
                'erpnext_user_id' => $erpnextUserId,
    
                'erpnext_api_key' => $erpnextApiKey,
    
                'erpnext_api_secret' => $erpnextApiSecret,
    
                'erpnext_token' => $erpnextToken,
    
                /*
                |--------------------------------------------------------------------------
                | NEW
                |--------------------------------------------------------------------------
                */
    
                'employee_id' => $erpnextEmployeeId,
    
                'active_warehouse' => $erpnextActiveWarehouse,
    
                'roles' => $roles,
    
                /*
                |--------------------------------------------------------------------------
                | Keep raw response for debugging if required
                |--------------------------------------------------------------------------
                */
    
                'raw_response' => $data,
            ];
    
        } catch (Exception $e) {
    
            Log::error(
                'ERPNext Auth Exception: ' . $e->getMessage()
            );
    
            throw $e;
        }
    }
}
