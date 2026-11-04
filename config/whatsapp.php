<?php

return [
    // Preserve existing installations until a gateway is explicitly selected.
    'provider' => env('WHATSAPP_GATEWAY', 'waha'),
];
