<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\GatewayConfig;
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
            $eventData = $request->all();
            $eventData['_gayatri_gateway'] = ['provider' => GatewayConfig::provider(),
                'session' => (string) $request->input('session', GatewayConfig::get('default_session')),
                'accepted_at' => now()->timestamp];
            Bus::dispatch(new ProcessIncomingWhatsAppMessageJob($eventData));
        }

        if ($event === 'message.ack') {
            $messageId = (string) ($payload['id'] ?? '');

            $ack = (int) ($payload['ack'] ?? 0);
            if ($messageId !== '' && $ack >= 2) {
                $changes = ['delivered_at' => now()];
                if ($ack >= 3) {
                    $changes['read_at'] = now();
                }
                Message::where('wa_message_id', $messageId)->update($changes);
                CampaignRecipient::where('wa_message_id', $messageId)->update($changes);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json(['message' => 'Unauthorized webhook.'], 401);
        }

        $session = (string) $request->input('session', GatewayConfig::get('default_session'));
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
        $expectedProvider = str_starts_with((string) $request->route()?->getName(), 'webhooks.whatsapp.') ? 'baileys' : 'waha';
        if ($expectedProvider !== GatewayConfig::provider()) {
            return false;
        }
        $secret = (string) GatewayConfig::get('webhook_secret');
        $apiKey = (string) GatewayConfig::get('api_key');

        $providedSecret = (string) $request->header('X-Webhook-Secret', $request->input('secret', ''));
        $providedApiKey = (string) $request->header('X-Api-Key', '');

        // Baileys always signs callbacks with its own dedicated webhook secret.
        if ($expectedProvider === 'baileys') {
            return $secret !== '' && hash_equals($secret, $providedSecret);
        }

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
        $value = preg_replace('/:\d+(?=@)/', '', $id);
        $value = str_replace(['@c.us', '@s.whatsapp.net'], '', $value);

        return $value !== '' ? $value : null;
    }
}
