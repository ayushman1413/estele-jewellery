<?php

namespace App\Services\Otp;

interface OtpGateway
{
    /**
     * Deliver a one-time code to a phone number. Implementations decide how
     * (SMS provider API, log, etc.) — callers never see the transport.
     * Returns false when the provider refused or could not be reached, so
     * the caller can tell the user instead of pretending a code went out.
     */
    public function send(string $phone, string $code): bool;
}
