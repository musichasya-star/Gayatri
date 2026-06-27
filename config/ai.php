<?php

return [
    'provider' => env('AI_PROVIDER'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL'),
    'temperature' => (float) env('AI_TEMPERATURE', 0.3),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 800),
];
