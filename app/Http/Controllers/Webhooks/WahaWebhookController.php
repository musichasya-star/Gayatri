<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class WahaWebhookController extends Controller
{
    public function messages(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['message' => 'Unauthorized webhook.'], 401);
        }

        $event = (string) $request->input('event');
        $payload = (array) $request->input('payload', []);

        if (in_array($event, ['message', 'message.any'], true) && ! ($payload['fromMe'] ?? false) && $this->isDirectCustomerChat($payload)) {
            Bus::dispatch(new ProcessIncomingWhatsAppMessageJob($request->all()));
        }

        if ($event === 'message.ack') {
            $messageId = (string) ($payload['id'] ?? '');

            if ($messageId !== '') {
                Message::where('wa_message_id', $messageId)->update([
                    'delivered_at' => now(),
                    'read_at' => (($payload['ack'] ?? 0) >= 3) ? now() : null,
                ]);
                CampaignRecipient::where('wa_message_id', $messageId)->update([
                    'delivered_at' => now(),
                    'read_at' => (($payload['ack'] ?? 0) >= 3) ? now() : null,
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['message' => 'Unauthorized webhook.'], 401);
        }

        $session = (string) $request->input('session', config('waha.default_session'));
        $status = (string) $request->input('payload.status', 'unknown');

        WhatsAppSession::updateOrCreate(
            ['session_name' => $session],
            [
                'status' => strtolower($status),
                'phone_number' => $this->normalizePhone((string) $request->input('me.id', '')),
                'connected_at' => $status === 'WORKING' ? now() : null,
                'last_seen_at' => now(),
                'meta' => $request->all(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    private function isAuthorized(Request $request): bool
    {
        $secret = (string) config('waha.webhook_secret');
        $apiKey = (string) config('waha.api_key');

        $providedSecret = (string) $request->header('X-Webhook-Secret', $request->input('secret', ''));
        $providedApiKey = (string) $request->header('X-Api-Key', '');

        if ($secret !== '' && hash_equals($secret, $providedSecret)) {
            return true;
        }

        if ($apiKey !== '' && hash_equals($apiKey, $providedApiKey)) {
            return true;
        }

        return $secret === '' && $apiKey === '';
    }

    private function isDirectCustomerChat(array $payload): bool
    {
        $chatId = (string) ($payload['from'] ?? $payload['chatId'] ?? '');

        if ($chatId === '') {
            return false;
        }

        return str_ends_with($chatId, '@c.us') || str_ends_with($chatId, '@s.whatsapp.net') || str_ends_with($chatId, '@lid');
    }

    private function normalizePhone(string $id): ?string
    {
        $value = str_replace(['@c.us', '@s.whatsapp.net'], '', $id);

        return $value !== '' ? $value : null;
    }
}
