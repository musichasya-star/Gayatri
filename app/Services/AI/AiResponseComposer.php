<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Support\AiDomain;

class AiResponseComposer
{
    public function compose(array $result, array $input): array
    {
        if (empty($result['reply'])) {
            return $result;
        }
        $preferences = app(AiConversationPreferences::class);
        $settings = $input['information_preferences'] ?? $preferences->settings();
        $state = isset($input['conversation_id']) ? app(AiConversationContext::class)->read(Conversation::find($input['conversation_id'])) : ($input['simulation_context'] ?? []);
        if (($state['expires_at'] ?? PHP_INT_MAX) < now()->timestamp) {
            $state = [];
        }
        $intent = $result['routing']['intent'] ?? '';
        $reason = $result['routing']['reason_code'] ?? '';
        $domain = $result['routing']['domain'] ?? null;
        $clarifying = in_array($reason, ['multiple_domains', 'knowledge_missing', 'domain_clarification'], true);
        $count = $clarifying ? ((int) ($state['clarification_count'] ?? 0) + 1) : 0;
        if ($clarifying && $count > $settings['max_clarifications']) {
            $result['status'] = 'escalated';
            $result['fallback_reason'] = 'repeated_clarification';
            $result['routing']['reason_code'] = 'repeated_clarification';
            $result['reply'] = 'Saya belum menemukan informasi yang cukup untuk menjawabnya. '.$settings['handoff'];
            $result['next_context']['domain'] = null;
            $result['next_context']['menu'] = null;
            $result['next_context']['lookup'] = null;
        } elseif ($intent === 'main_menu') {
            $parts = explode("\n\n", $result['reply'], 2);
            $result['reply'] = $settings['greeting']."\n\n".($parts[1] ?? $result['reply']);
        } elseif ($intent === 'thanks') {
            $result['reply'] = $settings['thanks'];
        } elseif ($intent === 'human_handoff') {
            $result['reply'] = $settings['handoff'];
        } elseif ($intent === 'domain_menu' && isset($settings['domain_prompts'][$domain ?? ''])) {
            $result['reply'] = AiDomain::LABELS[$domain]."\n".$settings['domain_prompts'][$domain]."\n\n1. Katalog & tarif\n2. Ketentuan layanan\n3. Pesan melalui web\n4. Cek status transaksi\n9. Menu utama\n0. Bicara dengan admin";
        } elseif ($reason === 'knowledge_missing') {
            $result['reply'] = $settings['fallback'];
        }
        $result['next_context']['clarification_count'] = $count;
        if ($reason === 'scoped_knowledge' && $settings['offer_next_step'] && in_array($domain, array_values(AiDomain::MENU), true)) {
            $facts = app(AiServiceDirectory::class)->facts($domain);
            $catalogAvailable = $facts['enabled'] && $facts['ai_enabled'] && count($facts['items']) > 0;
            if (! in_array($state['menu'] ?? null, ['offer_catalog', 'offer_admin'], true) || ($state['domain'] ?? null) !== $domain) {
                $result['reply'] .= "\n\n".($catalogAvailable ? match ($domain) {
                    'spa' => 'Mau lihat pilihan treatment dan tarifnya?', 'lactation' => 'Mau lihat pilihan konsultasi dan tarifnya?', 'rental' => 'Mau lihat katalog alat dan tarif sewanya?', 'laundry' => 'Mau lihat pilihan laundry dan tarifnya?'
                } : 'Mau dibantu admin untuk memilih layanan yang sesuai?');
            }
            $result['next_context']['menu'] = $catalogAvailable ? 'offer_catalog' : 'offer_admin';
        }
        if ($result['status'] === 'escalated' && ! $preferences->adminOpen($settings)) {
            $result['reply'] .= "\n\n".$settings['after_hours'];
        }
        $result['reply'] = $preferences->render($result['reply'], $settings);
        // Replace greetings in built-in sentences only; no customer data or free-form provider instructions are used.
        $result['reply'] = preg_replace_callback('/\bBunda\b/u', fn () => $settings['salutation'], $result['reply']);
        if (! $settings['use_emoji']) {
            $result['reply'] = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $result['reply']);
        }
        if ($settings['tone'] === 'professional') {
            $result['reply'] = str_replace([', ya.', 'Mau cari', 'Mau lihat', 'Mau tahu'], ['.', 'Ingin mencari', 'Ingin melihat', 'Ingin mengetahui'], $result['reply']);
        }
        if ($settings['tone'] === 'concise' && $intent === 'main_menu' && $settings['greeting'] === $preferences->defaults()['greeting']) {
            $result['reply'] = 'Halo '.$settings['salutation'].', pilih layanan atau tulis pertanyaan.'."\n\n".explode("\n\n", $result['reply'], 2)[1];
        }
        $result['evidence']['preferences'] = $preferences->fingerprint();
        $result['routing']['admin_open'] = $preferences->adminOpen($settings);

        return $result;
    }
}
