<?php

namespace App\Modules\CustomerCare\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramService
{
    protected string $apiVersion;
    protected string $accountId;
    protected string $accessToken;

    public function __construct()
    {
        $version = config('services.instagram.api_version');
        $this->apiVersion = (!empty($version)) ? (string) $version : 'v21.0';
        $this->accountId = (string) (config('services.instagram.account_id') ?? '');
        $this->accessToken = (string) (config('services.instagram.access_token') ?? '');
    }

    /**
     * Send an Instagram Direct text message.
     */
    public function sendText(string $recipientIgsid, string $message): array
    {
        if (empty($this->accessToken)) {
            throw new RuntimeException('Instagram Access Token is not configured.');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/me/messages',
            $this->apiVersion
        );

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($url, [
                'recipient' => [
                    'id' => trim($recipientIgsid),
                ],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'text' => $message,
                ],
            ]);

        if ($response->failed()) {
            Log::error('Instagram Direct message sending failed', [
                'status' => $response->status(),
                'response' => $response->json(),
                'recipient' => $recipientIgsid,
            ]);

            throw new RuntimeException(
                'Instagram API error: ' . ($response->json('error.message') ?? 'Unknown error')
            );
        }

        return $response->json();
    }

    /**
     * Send a media attachment via Instagram Direct.
     */
    public function sendAttachment(string $recipientIgsid, string $type, string $url): array
    {
        if (empty($this->accessToken)) {
            throw new RuntimeException('Instagram Access Token is not configured.');
        }

        $endpoint = sprintf(
            'https://graph.facebook.com/%s/me/messages',
            $this->apiVersion
        );

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($endpoint, [
                'recipient' => [
                    'id' => trim($recipientIgsid),
                ],
                'message' => [
                    'attachment' => [
                        'type' => $type, // image, video, audio
                        'payload' => [
                            'url' => $url,
                            'is_reusable' => true,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Instagram Direct attachment sending failed', [
                'status' => $response->status(),
                'response' => $response->json(),
                'recipient' => $recipientIgsid,
            ]);

            throw new RuntimeException(
                'Instagram API error: ' . ($response->json('error.message') ?? 'Unknown error')
            );
        }

        return $response->json();
    }

    /**
     * Fetch Instagram user profile from Meta Graph API using IGSID.
     */
    public function getUserProfile(string $igsid): ?array
    {
        if (empty($this->accessToken)) {
            return null;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            $this->apiVersion,
            trim($igsid)
        );

        try {
            $response = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(10)
                ->get($url, [
                    'fields' => 'name,username,profile_pic',
                ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning('Could not fetch Instagram user profile: ' . $e->getMessage(), [
                'igsid' => $igsid,
            ]);
        }

        return null;
    }
}

