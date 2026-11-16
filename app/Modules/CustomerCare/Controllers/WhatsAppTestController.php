<?php

namespace App\Modules\CustomerCare\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomerCare\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppTestController extends Controller
{
    protected WhatsAppService $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Send a test WhatsApp message.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'to' => 'nullable|string',
            'message' => 'nullable|string',
            'type' => 'nullable|in:text,template',
            'template_name' => 'nullable|string',
        ]);

        $defaultTo = config('services.whatsapp.test_recipient')
            ?: env('WHATSAPP_TEST_RECIPIENT', env('WHATSAPP_MOBILE_NUMBER'));

        $to = $request->input('to') ?: $defaultTo;

        if (empty($to)) {
            return response()->json([
                'success' => false,
                'message' => 'Recipient phone number (to) is required. Please pass {"to": "+974XXXXXXXX"} in request body.',
            ], 422);
        }

        $type = $request->input('type', 'text');
        $message = $request->input('message', 'Hello from Rakonex Customer Care! This is a test WhatsApp message.');
        $templateName = $request->input('template_name', 'hello_halamama');

        try {
            if ($type === 'template') {
                $response = $this->whatsAppService->sendTemplate($to, $templateName);
            } else {
                $response = $this->whatsAppService->sendText($to, $message);
            }

            Log::info('WhatsApp test message sent successfully', [
                'to' => $to,
                'type' => $type,
                'response' => $response,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp test message sent successfully.',
                'recipient' => $this->whatsAppService->normalizePhoneNumber($to),
                'type' => $type,
                'data' => $response,
            ]);
        } catch (Throwable $e) {
            Log::error('WhatsApp test send failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'hint' => str_contains($e->getMessage(), '24 hours') || str_contains($e->getMessage(), '131047')
                    ? 'Freeform text can only be sent within 24 hours of user messaging. Try sending with {"type": "template", "template_name": "hello_halamama"}.'
                    : null,
            ], 500);
        }
    }
}
