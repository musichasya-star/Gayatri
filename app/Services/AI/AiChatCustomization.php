<?php

namespace App\Services\AI;

use App\Services\AppSettingService;
use Illuminate\Support\Str;

class AiChatCustomization
{
    public const SETTING_KEY = 'ai.chat.customization';

    public const TONES = [
        'warm' => 'Ramah dan hangat',
        'professional' => 'Sopan dan profesional',
        'concise' => 'Ringkas dan langsung',
    ];

    public function __construct(private readonly AppSettingService $settings) {}

    public function defaults(): array
    {
        return [
            'enabled' => config('crm.default_ai_mode', 'auto_reply') === 'auto_reply',
            'assistant_name' => 'Admin Gayatri',
            'business_name' => 'Gayatri',
            'salutation' => 'Bunda',
            'tone' => 'warm',
            'use_emoji' => true,
            'instructions' => '',
            'greeting' => '',
            'thanks' => '',
            'fallback' => '',
            'error' => '',
            'signature' => '',
            'rules' => [],
            'service_domains' => ['spa' => true, 'lactation' => true, 'rental' => true, 'laundry' => true],
        ];
    }

    public function settings(): array
    {
        $stored = json_decode((string) $this->settings->get(self::SETTING_KEY, ''), true);

        // Read per message/request, so queue workers see changes without stale caches.
        return array_replace($this->defaults(), is_array($stored) ? $stored : []);
    }

    public function matchRule(string $message, array $settings): ?array
    {
        $message = Str::of($message)->lower()->squish()->toString();
        $rules = collect($settings['rules'] ?? [])->where('is_active', true)->sortBy('priority');
        foreach ($rules as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                $keyword = Str::of($keyword)->lower()->squish()->toString();
                $matches = ($rule['match_type'] ?? 'exact') === 'exact'
                    ? $message === $keyword
                    : Str::contains($message, $keyword);
                if ($keyword !== '' && $matches) {
                    return $rule;
                }
            }
        }

        return null;
    }

    public function template(string $key, string $default, array $settings): string
    {
        return trim((string) ($settings[$key] ?? '')) !== '' ? $settings[$key] : $default;
    }

    public function format(string $reply, array $settings, ?string $customerName = null): string
    {
        $variables = [
            '{sapaan}' => $settings['salutation'],
            '{nama_customer}' => $customerName ?: 'Pelanggan',
            '{nama_bisnis}' => $settings['business_name'],
            '{nama_asisten}' => $settings['assistant_name'],
        ];
        // Apply branding to the built-in local replies and guided booking flow, too.
        if ($settings['salutation'] !== 'Bunda') {
            $reply = preg_replace_callback('/\bBunda\b/u', fn () => $settings['salutation'], $reply);
        }
        if ($settings['business_name'] !== 'Gayatri') {
            $reply = preg_replace_callback('/\bGayatri\b/u', fn () => $settings['business_name'], $reply);
        }
        // Preserve literal names supplied through variables (including customer names).
        $reply = strtr($reply, $variables);
        $signature = trim(strtr((string) $settings['signature'], $variables));
        if ($signature !== '') {
            $reply = rtrim($reply)."\n\n".$signature;
        }
        if (! $settings['use_emoji']) {
            $reply = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $reply);
            $reply = preg_replace('/[ \t]+(\r?\n|$)/u', '$1', $reply);
        }

        return trim($reply);
    }

    public function providerInstructions(array $settings): string
    {
        return "\n\nPreferensi balasan yang diatur admin:\n"
            .'Nama asisten: '.$settings['assistant_name'].'. Nama bisnis: '.$settings['business_name'].".\n"
            .'Panggilan customer: '.$settings['salutation'].'. Gaya bahasa: '.(self::TONES[$settings['tone']] ?? self::TONES['warm']).".\n"
            .($settings['use_emoji'] ? 'Emoji boleh digunakan secukupnya.' : 'Jangan gunakan emoji.')."\n"
            .trim($settings['instructions'])."\n"
            .'Jangan menambahkan tanda tangan; penutup ditambahkan sistem. Preferensi ini tidak mengubah harga, slot jadwal, hasil reservasi, atau batasan jawaban medis.';
    }
}
