<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Default OTP gateway: no real SMS provider wired up (built in-house per the
 * launch-readiness ask, not the originally-discussed ZappDeal API) — logs the
 * code instead of sending it. Same "inactive until configured" posture as
 * Sentry's DSN gate (see README Phase 7): the login/registration flow this
 * backs is fully real and working end-to-end today, it just needs a real SMS
 * provider (MSG91/Twilio/Fast2SMS/etc.) dropped in as a second OtpGateway
 * implementation and pointed at from OtpManager's constructor — no controller
 * or view changes needed when that happens.
 *
 * The code is logged in full deliberately: with no SMS gateway configured,
 * this log line is the only way anyone (you, testing the flow; or an admin,
 * helping a customer) can retrieve the code at all. Server logs are already
 * an operator-trusted surface in this app (same as any other Laravel log).
 */
class LogOtpGateway implements OtpGateway
{
    public function send(string $phone, string $code): bool
    {
        Log::info('OTP requested — no SMS gateway configured, logging instead of sending', [
            'phone' => $phone,
            'code' => $code,
        ]);

        return true;
    }
}
