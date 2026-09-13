<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Meta WhatsApp Business Cloud API transport. Active when WHATSAPP_PROVIDER
 * is "cloud_api" and the three credentials below are set; until then the
 * container keeps handing out LogWhatsAppGateway (see AppServiceProvider).
 *
 *   WHATSAPP_API_URL     e.g. https://graph.facebook.com/v20.0
 *   WHATSAPP_API_KEY     permanent system-user access token
 *   WHATSAPP_FROM_NUMBER the phone-number-id of the sending number
 */
class CloudApiWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $fromNumber,
    ) {}

    public function send(string $to, string $message): void
    {
        $recipient = $this->normalise($to);

        if ($recipient === '') {
            return;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post(rtrim($this->apiUrl, '/')."/{$this->fromNumber}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => ['preview_url' => true, 'body' => $message],
                ]);
        } catch (ConnectionException $exception) {
            Log::error('WhatsApp Cloud API request failed', [
                'exception' => $exception::class,
                'to_suffix' => substr($recipient, -4),
            ]);

            return;
        }

        if ($response->failed()) {
            Log::error('WhatsApp Cloud API returned an error', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
                'to_suffix' => substr($recipient, -4),
            ]);
        }
    }

    /**
     * Cloud API wants E.164 digits with no plus. Bare ten-digit Indian
     * numbers (how the panel stores vendor mobiles) get the 91 prefix.
     */
    private function normalise(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return strlen($digits) === 10 ? '91'.$digits : $digits;
    }
}
