<?php

namespace App\Modules\CustomerCare\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomerCare\Models\CcConversation;
use App\Modules\CustomerCare\Models\CcCustomer;
use App\Modules\CustomerCare\Models\CcMessage;
use App\Modules\CustomerCare\Services\MessengerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessengerWebhookController extends Controller
{
    /**
     * Verify Facebook Messenger webhook subscription with Meta.
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

        $verifyToken = trim((string) config('services.facebook.verify_token'));
        $receivedToken = trim((string) $token);

        $isModeValid = $mode === 'subscribe';
        $isTokenValid = $verifyToken !== ''
            && $receivedToken !== ''
            && hash_equals($verifyToken, $receivedToken);

        if (empty($mode)) {
            Log::debug('Facebook Messenger webhook visited without hub_mode', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'status' => 'active',
                'service' => 'Customer Care Facebook Messenger Webhook',
                'timestamp' => now()->toIso8601String(),
                'configuration' => [
                    'verify_token_configured' => $verifyToken !== '',
                    'page_id_configured' => !empty(config('services.facebook.page_id')),
                    'page_token_configured' => !empty(config('services.facebook.page_access_token')),
                    'app_secret_configured' => !empty(config('services.facebook.app_secret')),
                    'api_version' => config('services.facebook.api_version'),
                ],
                'callback_url' => url('/api/customer-care/messenger/webhook'),
                'message' => 'Facebook Messenger Webhook endpoint is active and ready for Meta registration.',
            ], 200);
        }

        if ($isModeValid && $isTokenValid && $challenge !== null) {
            Log::info('Facebook Messenger webhook verified successfully', [
                'hub_mode' => $mode,
                'ip' => $request->ip(),
            ]);

            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        Log::warning('Facebook Messenger webhook verification failed', [
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
     * Receive incoming Facebook Messenger events from Meta.
     */
    public function receive(Request $request, MessengerService $messengerService): JsonResponse
    {
        $appSecret = config('services.facebook.app_secret');

        if (!empty($appSecret)) {
            $signature = $request->header('X-Hub-Signature-256');
            $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

            if (empty($signature) || !hash_equals($expectedSignature, $signature)) {
                Log::warning('Facebook Messenger webhook invalid signature', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook signature.',
                ], 401);
            }
        }

        $payload = $request->json()->all();
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $messagingEvents = $entry['messaging'] ?? [];

            foreach ($messagingEvents as $messaging) {
                $senderId = $messaging['sender']['id'] ?? null;
                $recipientId = $messaging['recipient']['id'] ?? null;
                $timestamp = $messaging['timestamp'] ?? null;

                // Handle incoming message
                if (!empty($messaging['message'])) {
                    $msgData = $messaging['message'];

                    // Ignore echo messages sent by the page itself
                    if (!empty($msgData['is_echo'])) {
                        continue;
                    }

                    $messageId = $msgData['mid'] ?? null;
                    $messageText = $msgData['text'] ?? null;
                    $attachments = $msgData['attachments'] ?? [];
                    $attachmentUrl = null;
                    $messageType = 'text';

                    if (!empty($attachments)) {
                        $firstAtt = $attachments[0] ?? [];
                        $messageType = $firstAtt['type'] ?? 'file';
                        $attachmentUrl = $firstAtt['payload']['url'] ?? null;
                        if (empty($messageText)) {
                            $messageText = '[' . ucfirst($messageType) . ' attachment]';
                        }
                    }

                    Log::info('Facebook Messenger incoming message', [
                        'from_psid' => $senderId,
                        'message_id' => $messageId,
                        'type' => $messageType,
                        'text' => $messageText,
                    ]);

                    try {
                        // 1. Resolve or create customer using facebook_id (PSID)
                        $customer = CcCustomer::where('facebook_id', $senderId)->first();

                        if (!$customer) {
                            $profile = $messengerService->getUserProfile((string) $senderId);
                            $name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));

                            $customer = CcCustomer::create([
                                'facebook_id' => $senderId,
                                'name' => !empty($name) ? $name : ('Facebook User ' . substr((string) $senderId, -4)),
                                'is_active' => true,
                            ]);
                        }

                        // 2. Resolve or create open conversation for Messenger
                        $conversation = CcConversation::firstOrCreate(
                            [
                                'customer_id' => $customer->id,
                                'channel' => 'messenger',
                                'status' => 'open',
                            ],
                            [
                                'channel_account_id' => (string) $recipientId,
                                'external_conversation_id' => (string) $senderId,
                                'last_message_at' => now(),
                                'last_message_preview' => Str::limit($messageText, 250),
                                'unread_count' => 0,
                            ]
                        );

                        // 3. Save message
                        CcMessage::firstOrCreate(
                            ['external_message_id' => $messageId],
                            [
                                'conversation_id' => $conversation->id,
                                'customer_id' => $customer->id,
                                'direction' => 'inbound',
                                'message_type' => $messageType,
                                'sender_type' => 'customer',
                                'sender_id' => null,
                                'message_body' => $messageText,
                                'attachment_url' => $attachmentUrl,
                                'delivery_status' => 'delivered',
                                'sent_at' => $timestamp ? Carbon::createFromTimestampMs((int) $timestamp) : now(),
                                'delivered_at' => now(),
                            ]
                        );

                        // 4. Update conversation
                        $conversation->increment('unread_count');
                        $conversation->update([
                            'last_message_at' => now(),
                            'last_message_preview' => Str::limit($messageText, 250),
                        ]);

                    } catch (\Throwable $e) {
                        Log::error('Failed to persist Facebook Messenger message: ' . $e->getMessage(), [
                            'message_id' => $messageId,
                            'sender_id' => $senderId,
                        ]);
                    }
                }

                // Handle delivery status update
                if (!empty($messaging['delivery'])) {
                    $mids = $messaging['delivery']['mids'] ?? [];
                    if (!empty($mids)) {
                        CcMessage::whereIn('external_message_id', $mids)
                            ->where('delivery_status', 'sent')
                            ->update([
                                'delivery_status' => 'delivered',
                                'delivered_at' => now(),
                            ]);
                    }
                }

                // Handle read status update
                if (!empty($messaging['read'])) {
                    $watermark = $messaging['read']['watermark'] ?? null;
                    if ($watermark && $senderId) {
                        $customer = CcCustomer::where('facebook_id', $senderId)->first();
                        if ($customer) {
                            CcMessage::where('customer_id', $customer->id)
                                ->where('direction', 'outbound')
                                ->where('delivery_status', '!=', 'read')
                                ->update([
                                    'delivery_status' => 'read',
                                    'read_at' => now(),
                                ]);
                        }
                    }
                }
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Get recent Facebook Messenger webhook logs.
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

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $filtered = array_values(array_filter($lines, function ($line) {
            return str_contains($line, 'Facebook') || str_contains($line, 'Messenger');
        }));

        $recent = array_slice($filtered, -30);

        return response()->json([
            'success' => true,
            'count' => count($recent),
            'logs' => $recent,
        ]);
    }
}

