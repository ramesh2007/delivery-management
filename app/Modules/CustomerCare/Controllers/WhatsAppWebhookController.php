<?php

namespace App\Modules\CustomerCare\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomerCare\Models\CcConversation;
use App\Modules\CustomerCare\Models\CcCustomer;
use App\Modules\CustomerCare\Models\CcMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verify webhook subscription with Meta.
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = $request->input('hub_mode')
            ?? $request->query('hub_mode')
            ?? $request->input('hub.mode')
            ?? $request->query('hub.mode');

        $token = $request->input('hub_verify_token')
            ?? $request->query('hub_verify_token')
            ?? $request->input('hub.verify_token')
            ?? $request->query('hub.verify_token');

        $challenge = $request->input('hub_challenge')
            ?? $request->query('hub_challenge')
            ?? $request->input('hub.challenge')
            ?? $request->query('hub.challenge');

        $verifyToken = trim((string) config('services.whatsapp.verify_token'));
        $receivedToken = trim((string) $token);

        $isModeValid = $mode === 'subscribe';

        $isTokenValid = $verifyToken !== ''
            && $receivedToken !== ''
            && hash_equals($verifyToken, $receivedToken);

        if (empty($mode)) {
            Log::debug('WhatsApp webhook visited without hub_mode', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'status' => 'active',
                'service' => 'Customer Care WhatsApp Webhook',
                'timestamp' => now()->toIso8601String(),
                'configuration' => [
                    'verify_token_configured' => $verifyToken !== '',
                    'api_version' => config('services.whatsapp.api_version'),
                    'app_secret_configured' => !empty(
                        config('services.whatsapp.app_secret')
                    ),
                ],
                'message' => 'WhatsApp Webhook endpoint is active.',
            ], 200);
        }

        if ($isModeValid && $isTokenValid && $challenge !== null) {
            Log::info('WhatsApp webhook verified successfully', [
                'hub_mode' => $mode,
                'ip' => $request->ip(),
            ]);

            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode_received' => $mode,
            'token_received' => $receivedToken ? '[REDACTED]' : null,
            'mode_valid' => $isModeValid,
            'token_valid' => $isTokenValid,
            'ip' => $request->ip(),
        ]);

        return response('Webhook verification failed', 403)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Receive WhatsApp messages and automatically reply to incoming text.
     */
    public function receive(Request $request): JsonResponse
    {
        $appSecret = config('services.whatsapp.app_secret');

        if (empty($appSecret)) {
            Log::error('WhatsApp App Secret is not configured.');

            return response()->json([
                'success' => false,
                'message' => 'WhatsApp App Secret is not configured.',
            ], 500);
        }

        // Validate Meta webhook signature.
        $signature = $request->header('X-Hub-Signature-256');

        $expectedSignature = 'sha256=' . hash_hmac(
            'sha256',
            $request->getContent(),
            $appSecret
        );

        if (
            empty($signature) ||
            !hash_equals($expectedSignature, $signature)
        ) {
            Log::warning('WhatsApp webhook invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $payload = $request->json()->all();

        $entries = $payload['entry'] ?? [];

        $events = [];
        $replyResults = [];

        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {

                $field = $change['field'] ?? 'unknown';
                $value = $change['value'] ?? [];

                // Process incoming messages.
                foreach ($value['messages'] ?? [] as $message) {

                    $messageId = $message['id'] ?? null;
                    $sender = $message['from'] ?? null;
                    $type = $message['type'] ?? 'unknown';
                    $timestamp = $message['timestamp'] ?? null;

                    $messageText = null;

                    if ($type === 'text') {
                        $messageText = $message['text']['body'] ?? null;
                    } elseif ($type === 'image' || $type === 'video' || $type === 'audio' || $type === 'document') {
                        $messageText = $message[$type]['caption'] ?? ('[' . ucfirst($type) . ' file]');
                    }

                    $events[] = [
                        'field' => $field,
                        'event' => 'message',
                        'message_id' => $messageId,
                        'from' => $sender,
                        'type' => $type,
                        'message_text' => $messageText,
                        'timestamp' => $timestamp,
                    ];

                    Log::info('WhatsApp incoming customer message', [
                        'from' => $sender,
                        'message_id' => $messageId,
                        'type' => $type,
                        'message_text' => $messageText,
                    ]);

                    // Persist Customer, Conversation, and Message to database
                    $customer = null;
                    $conversation = null;

                    try {
                        $contactName = null;
                        foreach ($value['contacts'] ?? [] as $contact) {
                            if (($contact['wa_id'] ?? null) === $sender) {
                                $contactName = $contact['profile']['name'] ?? null;
                                break;
                            }
                        }

                        $customer = CcCustomer::firstOrCreate(
                            ['whatsapp_id' => $sender],
                            [
                                'name' => $contactName ?: ('WhatsApp Customer ' . substr($sender, -4)),
                                'phone' => $sender,
                                'is_active' => true,
                            ]
                        );

                        if (!empty($contactName) && (empty($customer->name) || str_starts_with($customer->name, 'WhatsApp Customer'))) {
                            $customer->update(['name' => $contactName]);
                        }

                        $conversation = CcConversation::firstOrCreate(
                            [
                                'customer_id' => $customer->id,
                                'channel' => 'whatsapp',
                                'status' => 'open',
                            ],
                            [
                                'channel_account_id' => config('services.whatsapp.phone_number_id'),
                                'last_message_at' => now(),
                                'last_message_preview' => Str::limit($messageText ?: ('[' . ucfirst($type) . ']'), 250),
                                'unread_count' => 0,
                            ]
                        );

                        CcMessage::firstOrCreate(
                            ['external_message_id' => $messageId],
                            [
                                'conversation_id' => $conversation->id,
                                'customer_id' => $customer->id,
                                'direction' => 'inbound',
                                'message_type' => $type,
                                'sender_type' => 'customer',
                                'message_body' => $messageText ?: ('[' . ucfirst($type) . ']'),
                                'delivery_status' => 'delivered',
                                'sent_at' => $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : now(),
                                'delivered_at' => now(),
                            ]
                        );

                        $conversation->increment('unread_count');
                        $conversation->update([
                            'last_message_at' => now(),
                            'last_message_preview' => Str::limit($messageText ?: ('[' . ucfirst($type) . ']'), 250),
                        ]);

                    } catch (\Throwable $e) {
                        Log::error('Failed to persist WhatsApp incoming message', [
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Automatically reply only to text messages.
                    if (
                        $type === 'text' &&
                        !empty($sender) &&
                        !empty($messageText) &&
                        !empty($messageId)
                    ) {
                        $reply = "Hello! Welcome to HalaMama Customer Care.\n\n"
                            . "Thank you for contacting us.\n"
                            . "Please share your order number so we can assist you.";

                        $result = $this->sendAutomaticReply(
                            $sender,
                            $reply,
                            $messageId
                        );

                        if ($result['success'] && empty($result['duplicate']) && $conversation && $customer) {
                            try {
                                $outboundId = $result['response']['messages'][0]['id'] ?? null;
                                CcMessage::create([
                                    'conversation_id' => $conversation->id,
                                    'customer_id' => $customer->id,
                                    'direction' => 'outbound',
                                    'message_type' => 'text',
                                    'sender_type' => 'system',
                                    'message_body' => $reply,
                                    'external_message_id' => $outboundId,
                                    'delivery_status' => 'sent',
                                    'sent_at' => now(),
                                ]);
                            } catch (\Throwable $e) {
                                Log::error('Failed to persist automatic reply message', ['error' => $e->getMessage()]);
                            }
                        }

                        $replyResults[] = [
                            'to' => $sender,
                            'message_id' => $messageId,
                            'success' => $result['success'],
                        ];
                    }
                }

                // Process sent / delivered / read / failed statuses.
                foreach ($value['statuses'] ?? [] as $status) {
                    $statusMsgId = $status['id'] ?? null;
                    $statusName = $status['status'] ?? null;

                    if ($statusMsgId && $statusName) {
                        try {
                            $updateData = ['delivery_status' => $statusName];
                            if ($statusName === 'delivered') {
                                $updateData['delivered_at'] = now();
                            } elseif ($statusName === 'read') {
                                $updateData['read_at'] = now();
                            } elseif ($statusName === 'failed') {
                                $updateData['error_message'] = json_encode($status['errors'] ?? []);
                            }

                            CcMessage::where('external_message_id', $statusMsgId)->update($updateData);
                        } catch (\Throwable $e) {
                            Log::error('Failed to update message delivery status', [
                                'message_id' => $statusMsgId,
                                'status' => $statusName,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $events[] = [
                        'field' => $field,
                        'event' => 'status',
                        'message_id' => $statusMsgId,
                        'status' => $statusName,
                        'recipient_id' => $status['recipient_id'] ?? null,
                        'timestamp' => $status['timestamp'] ?? null,
                        'errors' => $status['errors'] ?? null,
                    ];
                }
            }
        }

        Log::info('WhatsApp webhook processed', [
            'object' => $payload['object'] ?? null,
            'entry_count' => count($entries),
            'events_count' => count($events),
            'reply_results' => $replyResults,
            'ip' => $request->ip(),
        ]);

        // Always acknowledge a valid webhook even if sending a reply fails.
        return response()->json([
            'success' => true,
            'message' => 'WhatsApp webhook processed successfully.',
        ], 200);
    }

    /**
     * Send an automatic text reply to a customer.
     */
    private function sendAutomaticReply(
        string $recipient,
        string $message,
        string $incomingMessageId
    ): array {

        $accessToken = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $apiVersion = config('services.whatsapp.api_version', 'v26.0');

        if (empty($accessToken) || empty($phoneNumberId)) {
            Log::error('WhatsApp reply configuration missing', [
                'token_configured' => !empty($accessToken),
                'phone_number_id_configured' => !empty($phoneNumberId),
            ]);

            return ['success' => false];
        }

        // Prevent duplicate replies if Meta retries the same webhook.
        $cacheKey = 'whatsapp_auto_reply_' . hash(
            'sha256',
            $incomingMessageId
        );

        if (!Cache::add($cacheKey, 'processing', now()->addHours(24))) {
            Log::info('WhatsApp duplicate incoming message ignored', [
                'message_id' => $incomingMessageId,
                'recipient' => $recipient,
            ]);

            return ['success' => true, 'duplicate' => true];
        }

        try {
            $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if (!$response->successful()) {
                // Allow a later webhook retry to attempt the reply.
                Cache::forget($cacheKey);

                Log::error('WhatsApp automatic reply failed', [
                    'recipient' => $recipient,
                    'incoming_message_id' => $incomingMessageId,
                    'http_status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return ['success' => false];
            }

            Log::info('WhatsApp automatic reply sent successfully', [
                'recipient' => $recipient,
                'incoming_message_id' => $incomingMessageId,
                'response' => $response->json(),
            ]);

            return [
                'success' => true,
                'response' => $response->json(),
            ];

        } catch (\Throwable $e) {

            Cache::forget($cacheKey);

            Log::error('WhatsApp automatic reply exception', [
                'recipient' => $recipient,
                'incoming_message_id' => $incomingMessageId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    /**
     * Get recent WhatsApp webhook logs.
     */
    public function logs(): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');

        if (!file_exists($logPath)) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'logs' => [],
            ]);
        }

        $lines = file(
            $logPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        $filtered = array_values(array_filter($lines, function ($line) {
            return str_contains($line, 'WhatsApp');
        }));

        $recent = array_slice($filtered, -30);

        return response()->json([
            'success' => true,
            'count' => count($recent),
            'logs' => $recent,
        ]);
    }
}
