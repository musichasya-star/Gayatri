<?php

namespace App\Services\WhatsApp;

class BaileysService extends HttpWhatsAppGateway
{
    protected function configPrefix(): string
    {
        return 'baileys';
    }
}
