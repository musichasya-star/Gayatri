<?php

namespace App\Services\WhatsApp;

class WahaService extends HttpWhatsAppGateway
{
    protected function configPrefix(): string
    {
        return 'waha';
    }
}
