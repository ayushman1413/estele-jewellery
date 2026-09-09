<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Default WhatsApp gateway: no WhatsApp Business API provider is configured
 * yet (WHATSAPP_PROVIDER is unset), so messages are logged instead of sent —
 * same "ready but inactive until configured" posture as LogOtpGateway. Swap
 * in a real provider later by adding one class implementing WhatsAppGateway
 * and rebinding it in AppServiceProvider — no caller changes needed.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $to, string $message): void
    {
        Log::info('WhatsApp message requested — no provider configured, logging instead of sending', [
            'to' => $to,
            'message' => $message,
        ]);
    }
}
