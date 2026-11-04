<?php

return [
    'base_url' => env('BAILEYS_BASE_URL', 'http://127.0.0.1:3001'),
    'api_key' => env('BAILEYS_API_KEY'),
    'default_session' => env('BAILEYS_DEFAULT_SESSION', 'gayatri-main'),
    'webhook_secret' => env('BAILEYS_WEBHOOK_SECRET'),
    'webhook_base_url' => env('BAILEYS_WEBHOOK_BASE_URL'),
    'timeout' => (int) env('BAILEYS_TIMEOUT_SECONDS', 30),
    'typing_enabled' => (bool) env('BAILEYS_TYPING_ENABLED', true),
    'typing_delay_ms' => (int) env('BAILEYS_TYPING_DELAY_MS', 1200),
];
