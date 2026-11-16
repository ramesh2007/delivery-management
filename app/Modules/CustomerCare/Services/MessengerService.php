<?php

namespace App\Modules\CustomerCare\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MessengerService
{
    protected string $apiVersion;
    protected string $pageId;
    protected string $pageAccessToken;

    public function __construct()
    {
        $version = config('services.facebook.api_version');
        $this->apiVersion = (!empty($version)) ? (string) $version : 'v21.0';
        $this->pageId = (string) (config('services.facebook.page_id') ?? '');
        $this->pageAccessToken = (string) (config('services.facebook.page_access_token') ?? '');
    }

    /**
     * Send a Facebook Messenger text message.
     */
    public function sendText(string $recipientPsid, string $message): array
    {
        if (empty($this->pageAccessToken)) {
            throw new RuntimeException('Facebook Page Access Token is not configured.');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/me/messages',
            $this->apiVersion
        );

        $response = Http::withToken($this->pageAccessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($url, [
                'recipient' => [
                    'id' => trim($recipientPsid),
                ],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'text' => $message,
                ],
            ]);

        if ($response->failed()) {
            Log::error('Facebook Messenger message sending failed', [
                'status' => $response->status(),
                'response' => $response->json(),
                'recipient' => $recipientPsid,
            ]);

            throw new RuntimeException(
                'Facebook Messenger API error: ' . ($response->json('error.message') ?? 'Unknown error')
            );
        }

        return $response->json();
    }

    /**
     * Send a media attachment (image, audio, video, or file) via Messenger.
     */
    public function sendAttachment(string $recipientPsid, string $type, string $url): array
    {
        if (empty($this->pageAccessToken)) {
            throw new RuntimeException('Facebook Page Access Token is not configured.');
        }

        $endpoint = sprintf(
            'https://graph.facebook.com/%s/me/messages',
            $this->apiVersion
        );

        $response = Http::withToken($this->pageAccessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($endpoint, [
                'recipient' => [
                    'id' => trim($recipientPsid),
                ],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'attachment' => [
                        'type' => $type, // image, video, audio, file
                        'payload' => [
                            'url' => $url,
                            'is_reusable' => true,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Facebook Messenger attachment sending failed', [
                'status' => $response->status(),
                'response' => $response->json(),
                'recipient' => $recipientPsid,
            ]);

            throw new RuntimeException(
                'Facebook Messenger API error: ' . ($response->json('error.message') ?? 'Unknown error')
            );
        }

        return $response->json();
    }

    /**
     * Fetch user profile from Meta Graph API using PSID.
     */
    public function getUserProfile(string $psid): ?array
    {
        if (empty($this->pageAccessToken)) {
            return null;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            $this->apiVersion,
            trim($psid)
        );

        try {
            $response = Http::withToken($this->pageAccessToken)
                ->acceptJson()
                ->timeout(10)
                ->get($url, [
                    'fields' => 'first_name,last_name,profile_pic',
                ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning('Could not fetch Facebook user profile: ' . $e->getMessage(), [
                'psid' => $psid,
            ]);
        }

        return null;
    }
}

