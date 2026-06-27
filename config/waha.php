<?php

return [
    'base_url' => env('WAHA_BASE_URL', 'http://localhost:3000'),
    'api_key' => env('WAHA_API_KEY'),
    'default_session' => env('WAHA_DEFAULT_SESSION', 'default'),
    'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
    'webhook_base_url' => env('WAHA_WEBHOOK_BASE_URL'),
    'timeout' => (int) env('WAHA_TIMEOUT_SECONDS', 30),
    'typing_enabled' => (bool) env('WAHA_TYPING_ENABLED', true),
    'typing_delay_ms' => (int) env('WAHA_TYPING_DELAY_MS', 1200),
];
