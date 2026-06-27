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

    public function applyToConfig(): void
    {
        config([
            'ai.provider' => $this->get('ai.provider', config('ai.provider')),
            'ai.api_key' => $this->get('ai.api_key', config('ai.api_key')),
            'ai.model' => $this->get('ai.model', config('ai.model')),
            'ai.temperature' => (float) $this->get('ai.temperature', config('ai.temperature', 0.3)),
            'ai.max_tokens' => (int) $this->get('ai.max_tokens', config('ai.max_tokens', 800)),
            'waha.base_url' => $this->get('waha.base_url', config('waha.base_url')),
            'waha.api_key' => $this->get('waha.api_key', config('waha.api_key')),
            'waha.default_session' => $this->get('waha.default_session', config('waha.default_session', 'default')),
            'waha.webhook_secret' => $this->get('waha.webhook_secret', config('waha.webhook_secret')),
            'waha.webhook_base_url' => $this->get('waha.webhook_base_url', config('waha.webhook_base_url')),
            'waha.timeout' => (int) $this->get('waha.timeout', config('waha.timeout', 30)),
        ]);
    }

    private function isSecret(string $key): bool
    {
        return in_array($key, ['ai.api_key', 'waha.api_key', 'waha.webhook_secret'], true);
    }
}
