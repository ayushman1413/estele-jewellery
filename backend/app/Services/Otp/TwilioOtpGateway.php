<?php

namespace App\Services\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Real SMS OTP delivery via Twilio's Messages API, called directly over HTTP
 * (same "no SDK dependency, Http facade + Basic Auth" shape as every other
 * gateway in this codebase — see VasMultimediaOtpGateway/ShiprocketService)
 * rather than pulling in twilio/sdk for one endpoint.
 *
 * Heads up baked into the number formatting below: Twilio's number here is a
 * US long code. Indian carriers commonly filter/block A2P SMS to +91 numbers
 * from foreign long codes regardless of what Twilio's API reports back — the
 * same "API says success, phone never gets it" failure mode already seen
 * with VAS Multimedia's trial credentials. isConfigured() only proves the
 * three values are present, not that delivery will actually work for Indian
 * recipients; that needs a real end-to-end send to confirm either way.
 */
class TwilioOtpGateway implements OtpGateway
{
    public function isConfigured(): bool
    {
        return filled(config('services.twilio.sid'))
            && filled(config('services.twilio.token'))
            && filled(config('services.twilio.from'));
    }

    public function send(string $phone, string $code): bool
    {
        $cleanPhone = substr(preg_replace('/\D/', '', $phone), -10);
        $to = '+91'.$cleanPhone;
        $sid = config('services.twilio.sid');

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->withBasicAuth($sid, config('services.twilio.token'))
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $to,
                    'From' => config('services.twilio.from'),
                    'Body' => "Your Estele login OTP is {$code}. Valid for 5 minutes. Do not share this code.",
                ]);
        } catch (ConnectionException $exception) {
            Log::error('Twilio SMS gateway request failed', [
                'exception' => $exception::class,
                'phone_suffix' => substr($cleanPhone, -4),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('Twilio SMS gateway returned a non-success response', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
                'phone_suffix' => substr($cleanPhone, -4),
            ]);
        }

        return $response->successful();
    }
}
