<?php

namespace App\Services\WhatsApp;

interface WhatsAppGateway
{
    /**
     * Send a WhatsApp message to a phone number. Implementations decide the
     * transport (WhatsApp Business API provider, log, etc.) — callers never
     * see it.
     */
    public function send(string $to, string $message): void;
}
