<?php

namespace App\Modules\CustomerCare\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomerCare\Models\CcConversation;
use App\Modules\CustomerCare\Models\CcCustomer;
use App\Modules\CustomerCare\Models\CcMessage;
use App\Modules\CustomerCare\Services\WhatsAppService;
use App\Modules\CustomerCare\Services\MessengerService;
use App\Modules\CustomerCare\Services\InstagramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    /**
     * List all customer care conversations (for React sidebar).
     */
    public function index(Request $request): JsonResponse
    {
        $query = CcConversation::with(['customer', 'assignedUser'])
            ->orderByDesc('last_message_at');

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('whatsapp_id', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $conversations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $conversations->items(),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Show a single conversation details.
     */
    public function show(int $id): JsonResponse
    {
        $conversation = CcConversation::with(['customer', 'assignedUser', 'tickets'])
            ->find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $conversation,
        ]);
    }

    /**
     * Get all messages for a specific conversation (for React chat window).
     * Supports ?after_id=X for lightweight polling in React.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        $conversation = CcConversation::with('customer')->find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $query = $conversation->messages()->orderBy('id', 'asc');

        if ($request->filled('after_id')) {
            $query->where('id', '>', (int) $request->input('after_id'));
        }

        $messages = $query->get();

        // Mark unread count as 0 when agent views the conversation
        if ($conversation->unread_count > 0 && !$request->filled('after_id')) {
            $conversation->update(['unread_count' => 0]);
        }

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'status' => $conversation->status,
                'customer' => $conversation->customer,
                'unread_count' => $conversation->unread_count,
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Reply to a customer from React.js (sends message via WhatsApp, Messenger, or Instagram).
     */
    public function reply(
        Request $request,
        int $id,
        WhatsAppService $whatsAppService,
        MessengerService $messengerService,
        InstagramService $instagramService
    ): JsonResponse {
        $validated = $request->validate([
            'message' => 'required|string|max:4096',
        ]);

        $messageText = trim($validated['message']);

        $conversation = CcConversation::with('customer')->find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found.',
            ], 404);
        }

        $customer = $conversation->customer;
        $channel = $conversation->channel ?: 'whatsapp';
        $recipient = null;
        $externalMessageId = null;

        try {
            if ($channel === 'messenger') {
                $recipient = $customer ? ($customer->facebook_id ?: $conversation->external_conversation_id) : $conversation->external_conversation_id;
                if (empty($recipient)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Customer does not have a valid Facebook Page-Scoped ID (PSID).',
                    ], 422);
                }
                $apiResponse = $messengerService->sendText((string) $recipient, $messageText);
                $externalMessageId = $apiResponse['message_id'] ?? null;

            } elseif ($channel === 'instagram') {
                $recipient = $customer ? ($customer->instagram_id ?: $conversation->external_conversation_id) : $conversation->external_conversation_id;
                if (empty($recipient)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Customer does not have a valid Instagram Scoped User ID (IGSID).',
                    ], 422);
                }
                $apiResponse = $instagramService->sendText((string) $recipient, $messageText);
                $externalMessageId = $apiResponse['message_id'] ?? null;

            } else {
                // WhatsApp default
                $recipient = $customer ? ($customer->phone ?: $customer->whatsapp_id) : null;
                if (empty($recipient)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Customer does not have a valid phone number or WhatsApp ID.',
                    ], 422);
                }
                $apiResponse = $whatsAppService->sendText((string) $recipient, $messageText);
                $externalMessageId = $apiResponse['messages'][0]['id'] ?? null;
            }

            // Persist message in database
            $message = CcMessage::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $customer ? $customer->id : null,
                'direction' => 'outbound',
                'message_type' => 'text',
                'sender_type' => auth()->check() ? 'agent' : 'system',
                'sender_id' => auth()->id(),
                'message_body' => $messageText,
                'external_message_id' => $externalMessageId,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ]);

            // Update conversation last message details
            $conversation->update([
                'last_message_at' => now(),
                'last_message_preview' => Str::limit($messageText, 250),
            ]);

            Log::info("Agent {$channel} reply sent", [
                'conversation_id' => $conversation->id,
                'recipient' => $recipient,
                'message_id' => $message->id,
                'external_message_id' => $externalMessageId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reply sent successfully.',
                'data' => $message,
            ]);

        } catch (\Throwable $e) {
            Log::error("Agent {$channel} reply failed", [
                'conversation_id' => $conversation->id,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start a new conversation to a customer phone number from React.js.
     */
    public function startNew(
        Request $request,
        WhatsAppService $whatsAppService
    ): JsonResponse {
        $validated = $request->validate([
            'phone' => 'required|string|min:8|max:20',
            'customer_name' => 'nullable|string|max:255',
            'message' => 'required|string|max:4096',
        ]);

        $phone = preg_replace('/[^0-9]/', '', $validated['phone']);
        $messageText = trim($validated['message']);
        $name = $validated['customer_name'] ?? null;

        DB::beginTransaction();
        try {
            // Find or create customer
            $customer = CcCustomer::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name ?: ('Customer ' . substr($phone, -4)),
                    'whatsapp_id' => $phone,
                    'is_active' => true,
                ]
            );

            // Find or create open conversation
            $conversation = CcConversation::firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'channel' => 'whatsapp',
                    'status' => 'open',
                ],
                [
                    'last_message_at' => now(),
                    'last_message_preview' => Str::limit($messageText, 250),
                    'unread_count' => 0,
                ]
            );

            // Send via WhatsApp
            $apiResponse = $whatsAppService->sendText($phone, $messageText);
            $externalMessageId = $apiResponse['messages'][0]['id'] ?? null;

            // Persist message
            $message = CcMessage::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'sender_type' => auth()->check() ? 'agent' : 'system',
                'sender_id' => auth()->id(),
                'message_body' => $messageText,
                'external_message_id' => $externalMessageId,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'last_message_preview' => Str::limit($messageText, 250),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Conversation started and message sent.',
                'conversation_id' => $conversation->id,
                'data' => $message,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Start new WhatsApp conversation failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start conversation: ' . $e->getMessage(),
            ], 500);
        }
    }
}

