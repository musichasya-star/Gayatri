<?php

namespace App\Services\AI;

use App\Models\AiAutomationLog;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\GatewayConfig;
use App\Support\ConversationStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiInteractionPolicy
{
    public const INFORMATION_WEB = 'information_web';

    public const OFF = 'off';

    /** Always read the database: long-lived workers must not cache this decision. */
    public function snapshot(): array
    {
        try {
            $row = DB::table('ai_interaction_policies')->where('id', 1)->first();
            if (! $row || ! in_array($row->mode, [self::INFORMATION_WEB, self::OFF], true) || $row->revision < 1) {
                return $this->unavailable();
            }
            $stored = DB::table('app_settings')->where('key', AiChatCustomization::SETTING_KEY)->value('value');
            $provider = DB::table('app_settings')->where('key', 'whatsapp.provider')->value('value') ?? GatewayConfig::provider();
            if (! in_array($provider, ['waha', 'baileys'], true)) {
                return $this->unavailable();
            }
            $settings = $stored === null ? [] : json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($settings) || (array_key_exists('enabled', $settings) && ! is_bool($settings['enabled']))) {
                return $this->unavailable();
            }
            if (isset($settings['service_domains']) && (! is_array($settings['service_domains'])
                || array_diff(array_keys($settings['service_domains']), ['spa', 'lactation', 'rental', 'laundry'])
                || array_filter($settings['service_domains'], fn ($enabled) => ! is_bool($enabled)))) {
                return $this->unavailable();
            }

            return [
                'mode' => $row->mode, 'revision' => (int) $row->revision, 'available' => true,
                'provider' => $provider,
                'enabled' => $row->mode === self::INFORMATION_WEB
                    && ($settings['enabled'] ?? config('crm.default_ai_mode', 'auto_reply') === 'auto_reply'),
            ];
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /** There is intentionally no configuration/environment switch enabling legacy writes. */
    public function allowsLegacyAutomation(): bool
    {
        return false;
    }

    public function blockAutomation(AiExtractedData $data, ?AiAutomationRule $rule = null): AiAutomationLog
    {
        $data->update(['status' => 'blocked']);

        return AiAutomationLog::firstOrCreate([
            'ai_extracted_data_id' => $data->id, 'ai_automation_rule_id' => $rule?->id,
            'status' => 'blocked', 'error_message' => 'transaction_action_blocked',
        ], [
            'conversation_id' => $data->conversation_id, 'message_id' => $data->message_id,
            'customer_id' => $data->customer_id, 'target_entity' => $rule?->target_entity,
            'action' => $rule?->action, 'mode' => self::INFORMATION_WEB, 'created_by_ai' => true,
            'output_payload' => ['reason_code' => 'transaction_action_blocked'],
        ]);
    }

    public function blockLegacyJob(AiExtractedData $data): bool
    {
        if ($this->allowsLegacyAutomation()) {
            return false;
        }
        $this->blockAutomation($data);

        return true;
    }

    public function canHandle(Conversation $conversation): bool
    {
        return (bool) $conversation->ai_enabled
            && $conversation->assigned_user_id === null
            && ! in_array($conversation->status, [ConversationStatus::HUMAN_HANDLED, ConversationStatus::CLOSED, ConversationStatus::ESCALATED], true)
            && $conversation->customer
            && ! in_array($conversation->customer->status, ['inactive', 'blocked', 'blacklisted'], true);
    }

    public function replyContext(Conversation $conversation, Message $source, string $reply, array $snapshot, bool $handoff = false, array $evidence = []): array
    {
        return [
            'version' => 1, 'policy_revision' => $snapshot['revision'], 'source_message_id' => $source->id,
            'customer_id' => $conversation->customer_id, 'chat_id' => $conversation->wa_chat_id,
            'session_id' => $conversation->whatsapp_session_id,
            'session_name' => $conversation->whatsappSession?->session_name ?: GatewayConfig::get('default_session'),
            'provider' => $snapshot['provider'] ?? GatewayConfig::provider(),
            'control_revision' => (int) $conversation->ai_control_revision,
            'expires_at' => now()->addMinutes(15)->timestamp, 'reply_hash' => hash('sha256', $reply), 'handoff' => $handoff,
            'evidence' => $evidence,
        ];
    }

    public function beforeResumeBoundary(Conversation $conversation, Message $source): bool
    {
        return $source->id <= (int) $conversation->ai_reply_after_message_id
            || ($conversation->ai_resumed_at && (! $source->sent_at || $source->sent_at->lte($conversation->ai_resumed_at)));
    }

    public function sendProblem(Conversation $conversation, string $reply, ?array $context): ?string
    {
        if ($this->allowsLegacyAutomation()) {
            return null; // Only substituted by the isolated legacy test double.
        }
        $snapshot = $this->snapshot();
        if (! $snapshot['available']) {
            return 'policy_unavailable';
        }
        if (! $snapshot['enabled']) {
            return 'ai_disabled';
        }
        if (! $conversation->customer || in_array($conversation->customer->status, ['inactive', 'blocked', 'blacklisted'], true)) {
            return 'recipient_changed';
        }
        if (! $context || ($context['version'] ?? null) !== 1
            || ! hash_equals(hash('sha256', $reply), (string) ($context['reply_hash'] ?? ''))) {
            return 'legacy_ai_context_missing';
        }
        $plannedHandoff = ($context['handoff'] ?? false) === true && $conversation->status === ConversationStatus::ESCALATED;
        if (! $this->canHandle($conversation) && ! $plannedHandoff) {
            return 'human_takeover';
        }
        if (($context['policy_revision'] ?? null) !== $snapshot['revision']) {
            return 'stale_policy';
        }
        if (($context['control_revision'] ?? null) !== (int) $conversation->ai_control_revision) {
            return 'stale_context';
        }
        if (! is_int($context['expires_at'] ?? null) || $context['expires_at'] < now()->timestamp) {
            return 'stale_context';
        }
        if (($context['customer_id'] ?? null) !== $conversation->customer_id
            || ($context['chat_id'] ?? null) !== $conversation->wa_chat_id
            || ($context['session_id'] ?? null) !== $conversation->whatsapp_session_id
            || ($context['session_name'] ?? null) !== ($conversation->whatsappSession?->session_name ?: GatewayConfig::get('default_session'))
            || ($context['provider'] ?? null) !== $snapshot['provider']
            || GatewayConfig::provider() !== $snapshot['provider']) {
            return 'recipient_changed';
        }
        $source = Message::find($context['source_message_id'] ?? null);
        if (! $source || $source->direction !== 'incoming' || $source->conversation_id !== $conversation->id
            || $source->customer_id !== $conversation->customer_id || $source->created_at->lt(now()->subMinutes(15))) {
            return 'stale_context';
        }
        if ($this->beforeResumeBoundary($conversation, $source)) {
            return 'stale_context';
        }
        if ($conversation->messages()->where('direction', 'incoming')->where('id', '>', $source->id)->exists()
            || $conversation->messages()->where('direction', 'outgoing')->where('sender_type', 'admin')->where('id', '>', $source->id)->exists()) {
            return 'stale_context';
        }

        try {
            return app(AiReplyEvidence::class)->problem($context['evidence'] ?? [], $conversation->id);
        } catch (Throwable) {
            return 'evidence_unavailable';
        }
    }

    private function unavailable(): array
    {
        return ['mode' => self::OFF, 'revision' => 0, 'available' => false, 'enabled' => false];
    }
}
