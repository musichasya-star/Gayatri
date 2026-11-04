<?php

namespace App\Services\WhatsApp;

class GatewayConfig
{
    public static function provider(): string
    {
        return config('whatsapp.provider') === 'baileys' ? 'baileys' : 'waha';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return config(self::provider().'.'.$key, $default);
    }

    public static function label(): string
    {
        return self::provider() === 'baileys' ? 'Baileys' : 'WAHA';
    }
}
