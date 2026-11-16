<?php

namespace App\Modules\CustomerCare\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppService
{
    protected string $apiVersion;
    protected string $phoneNumberId;
    protected string $accessToken;

    public function __construct()
    {
        $version = config('services.whatsapp.api_version');
        $this->apiVersion = (!empty($version) && $version !== 'vXX.X') ? (string) $version : 'v26.0';
        $this->phoneNumberId = (string) (config('services.whatsapp.phone_number_id') ?? '');
        $this->accessToken = (string) (config('services.whatsapp.access_token') ?? '');
    }

    /**
     * Send a WhatsApp text message.
     */
    public function sendText(string $to, string $message): array
    {
        if (
            empty($this->apiVersion) ||
            empty($this->phoneNumberId) ||
            empty($this->accessToken)
        ) {
            throw new RuntimeException(
                'WhatsApp API configuration is incomplete.'
            );
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $this->phoneNumberId
        );

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->normalizePhoneNumber($to),
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message,
                ],
            ]);

        if ($response->failed()) {
            Log::error('WhatsApp message sending failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new RuntimeException(
                'WhatsApp API error: ' .
                ($response->json('error.message') ?? $response->body())
            );
        }

        return $response->json();
    }

    /**
     * Send a WhatsApp template message.
     */
    public function sendTemplate(string $to, string $templateName = 'hello_halamama', string $language = 'en', array $components = []): array
    {
        if (
            empty($this->apiVersion) ||
            empty($this->phoneNumberId) ||
            empty($this->accessToken)
        ) {
            throw new RuntimeException(
                'WhatsApp API configuration is incomplete.'
            );
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $this->phoneNumberId
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizePhoneNumber($to),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $language,
                ],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('WhatsApp template message sending failed', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new RuntimeException(
                'WhatsApp API error: ' .
                ($response->json('error.message') ?? $response->body())
            );
        }

        return $response->json();
    }

    /**
     * Normalize phone number to international format.
     * Example: +974XXXXXXXX becomes 974XXXXXXXX.
     */
    public function normalizePhoneNumber(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }
}