<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppSettingService
{
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return $default;
            }

            $value = AppSetting::where('key', $key)->value('value');

            if ($value === null) {
                return $default;
            }

            if ($this->isSecret($key)) {
                try {
                    return Crypt::decryptString($value);
                } catch (Throwable) {
                    return $value;
                }
            }

            return $value;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value): void
    {
        $storedValue = $value === null ? null : (string) $value;

        if ($storedValue !== null && $this->isSecret($key)) {
            $storedValue = Crypt::encryptString($storedValue);
        }

        AppSetting::updateOrCreate(['key' => $key], ['value' => $storedValue]);
    }

    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function aiApiKey(string $provider): string
    {
        if (! in_array($provider, ['openai', 'openrouter', 'gemini', 'deepseek'], true)) {
            return '';
        }
        $scoped = $this->get('ai.providers.'.$provider.'.api_key');
        if ($scoped !== null) {
            return $this->aiKeyMismatch($provider, (string) $scoped) === null ? (string) $scoped : '';
        }
        $legacy = (string) $this->get('ai.api_key', config('ai.api_key', ''));
        $owner = $this->get('ai.api_key_provider', $this->get('ai.provider', config('ai.provider', 'local')));

        return $owner === $provider && $this->aiKeyMismatch($provider, $legacy) === null ? $legacy : '';
    }

    /** Reject recognizable keys from another provider without disclosing their value. */
    public function aiKeyMismatch(string $provider, string $key): ?string
    {
        $owner = match (true) {
            str_starts_with($key, 'sk-or-') => 'openrouter',
            str_starts_with($key, 'sk-proj-'), str_starts_with($key, 'sk-svcacct-') => 'openai',
            str_starts_with($key, 'AIza') => 'gemini',
            default => null,
        };

        return $owner !== null && $owner !== $provider
            ? 'API key ini milik '.ucfirst($owner).'. Pilih provider yang sesuai atau masukkan API key '.ucfirst($provider).'.'
            : null;
    }

    public function applyToConfig(): void
    {
        $provider = (string) $this->get('ai.provider', config('ai.provider', 'local'));
        config([
            'whatsapp.provider' => $this->get('whatsapp.provider', config('whatsapp.provider', 'waha')),
            'ai.provider' => $provider,
            'ai.api_key' => $this->aiApiKey($provider),
            'ai.model' => $this->get('ai.model', config('ai.model')),
            'ai.temperature' => (float) $this->get('ai.temperature', config('ai.temperature', 0.3)),
            'ai.max_tokens' => (int) $this->get('ai.max_tokens', config('ai.max_tokens', 800)),
            'waha.base_url' => $this->get('waha.base_url', config('waha.base_url')),
            'waha.api_key' => $this->get('waha.api_key', config('waha.api_key')),
            'waha.default_session' => $this->get('waha.default_session', config('waha.default_session', 'default')),
            'waha.webhook_secret' => $this->get('waha.webhook_secret', config('waha.webhook_secret')),
            'waha.webhook_base_url' => $this->get('waha.webhook_base_url', config('waha.webhook_base_url')),
            'waha.timeout' => (int) $this->get('waha.timeout', config('waha.timeout', 30)),
            'baileys.base_url' => $this->get('baileys.base_url', config('baileys.base_url')),
            'baileys.api_key' => $this->get('baileys.api_key', config('baileys.api_key')),
            'baileys.default_session' => $this->get('baileys.default_session', config('baileys.default_session', 'gayatri-main')),
            'baileys.webhook_secret' => $this->get('baileys.webhook_secret', config('baileys.webhook_secret')),
            'baileys.webhook_base_url' => $this->get('baileys.webhook_base_url', config('baileys.webhook_base_url')),
            'baileys.timeout' => (int) $this->get('baileys.timeout', config('baileys.timeout', 30)),
        ]);
    }

    private function isSecret(string $key): bool
    {
        return in_array($key, ['ai.api_key', 'waha.api_key', 'waha.webhook_secret', 'baileys.api_key', 'baileys.webhook_secret'], true)
            || preg_match('/^ai\.providers\.(openai|openrouter|gemini|deepseek)\.api_key$/D', $key) === 1;
    }
}
