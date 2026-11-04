<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Real Razorpay API client. Only ever consulted when RAZORPAY_KEY_ID and
 * RAZORPAY_KEY_SECRET are set (see isConfigured()) — PaymentManager keeps
 * checkout COD-only otherwise, so this class is safe to leave wired in
 * before a Razorpay account exists (same pattern as ShiprocketService).
 */
class RazorpayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function isConfigured(): bool
    {
        return filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.key_secret'));
    }

    /**
     * @return array{id: string, amount: int, currency: string}
     */
    public function createOrder(Order $order): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Razorpay is not configured.');
        }

        return Http::withBasicAuth(config('services.razorpay.key_id'), config('services.razorpay.key_secret'))
            // Transient network blips (DNS hiccups, a dropped TLS handshake)
            // shouldn't surface "payment unavailable" to a customer on the
            // very first hitch — retry connection-level failures and 5xx
            // responses a few times before giving up. Never retry a 4xx
            // (bad credentials, malformed request): that's deterministic and
            // an extra attempt only risks creating a duplicate Razorpay order.
            ->retry(3, 300, function (\Throwable $exception) {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            })
            ->post(self::BASE_URL.'/orders', [
                'amount' => $this->rupeesToPaise($order->total),
                'currency' => 'INR',
                'receipt' => $order->order_number,
                'notes' => [
                    'order_number' => $order->order_number,
                ],
            ])
            ->throw()
            ->json();
    }

    public function verifyPaymentSignature(string $razorpayOrderId, string $razorpayPaymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', "{$razorpayOrderId}|{$razorpayPaymentId}", (string) config('services.razorpay.key_secret'));

        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signatureHeader): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        // An empty key still produces a valid-looking HMAC — anyone can
        // compute hash_hmac(..., '') themselves, so an unconfigured secret
        // must never verify a signature rather than silently accepting
        // whatever a forged request claims.
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Razorpay's Orders API (and its checkout.js widget) want an integer
     * amount in the smallest currency unit (paise), not a decimal rupee
     * amount. Order::total comes back as a numeric string via the
     * decimal:2 cast (e.g. "499.00") — cast to float before multiplying and
     * round explicitly rather than truncating, so a value like "10.10"
     * becomes 1010 paise, not 1009 from float drift. Public: the checkout
     * page needs the exact same figure to render the payment widget.
     */
    public function rupeesToPaise(string|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
