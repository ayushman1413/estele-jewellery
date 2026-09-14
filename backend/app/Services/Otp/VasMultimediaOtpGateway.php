<?php

namespace App\Services\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real SMS OTP delivery via VAS Multimedia's bulk-SMS gateway
 * (vas.themultimedia.in) — the same provider already used by the user's other
 * project (ZappDeal, D:\zapp\app\Services\SmsService.php), ported over rather
 * than reimplemented so both projects share one proven integration. Behavior
 * mirrors that file closely on purpose: same endpoint, same params, same
 * "success"/"submitted"/"sent"/"ok"/hex-id response-body heuristic (this
 * provider doesn't return a consistent JSON shape, just a short plaintext
 * status token).
 *
 * "ZappDeal API" in the original ask turned out to mean "the SMS gateway
 * ZappDeal (the user's other Laravel app) already uses" — VAS Multimedia,
 * not some ZappDeal-branded OTP service — confirmed by reading that
 * project's actual working SmsService rather than guessing.
 *
 * Inactive until configured: VAS_SMS_API_KEY/ENTITY_ID/TEMPLATE_ID are blank
 * in ZappDeal's own .env too (same "not obtained yet" state there), so
 * isConfigured() gates this off and OtpManager falls back to LogOtpGateway
 * until real credentials exist — same posture as ShiprocketService/
 * RazorpayGateway elsewhere in this codebase.
 */
class VasMultimediaOtpGateway implements OtpGateway
{
    public function isConfigured(): bool
    {
        return filled(config('services.vas_sms.api_key'))
            && filled(config('services.vas_sms.entity_id'))
            && filled(config('services.vas_sms.template_id'));
    }

    public function send(string $phone, string $code): bool
    {
        $cleanPhone = substr(preg_replace('/\D/', '', $phone), -10);
        $request = Http::timeout(15);

        $caBundle = config('services.vas_sms.ca_bundle');
        if (is_string($caBundle) && $caBundle !== '') {
            $request = $request->withOptions(['verify' => $caBundle]);
        }

        try {
            $response = $request->get('https://vas.themultimedia.in/domestic/sendsms/bulksms_v2.php', [
                'apikey' => config('services.vas_sms.api_key'),
                'type' => 'TEXT',
                'sender' => config('services.vas_sms.sender'),
                'entityId' => config('services.vas_sms.entity_id'),
                'templateId' => config('services.vas_sms.template_id'),
                'mobile' => $cleanPhone,
                'message' => sprintf(config('services.vas_sms.template'), $code),
            ]);
        } catch (ConnectionException $exception) {
            Log::error('VAS Multimedia SMS gateway request failed', [
                'exception' => $exception::class,
                'phone_suffix' => substr($cleanPhone, -4),
            ]);

            return false;
        }

        $ok = $response->successful() && preg_match('/success|submitted|sent|ok|^[\da-f-]+$/i', trim($response->body()));

        if ($ok) {
            Log::info('VAS Multimedia SMS gateway accepted the OTP request', [
                'phone_suffix' => substr($cleanPhone, -4),
            ]);
        } else {
            Log::error('VAS Multimedia SMS gateway returned a non-success response', [
                'status' => $response->status(),
                'body' => \Illuminate\Support\Str::limit($response->body(), 200),
                'phone_suffix' => substr($cleanPhone, -4),
            ]);
        }

        return $ok;
    }
}
