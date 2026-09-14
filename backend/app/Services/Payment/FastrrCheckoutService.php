<?php

namespace App\Services\Payment;

use App\Models\Address;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shiprocket Fastrr (headless) Checkout. Two responsibilities:
 *
 *  - Sign requests to checkout-api.shiprocket.com and fetch the one-time
 *    access token that the checkout script needs to open its iframe.
 *  - Translate between our catalogue and the single numeric "variant_id"
 *    Fastrr keys everything on (see variantIdFor / resolveVariant).
 *
 * The token endpoint path and the response key are read from config so a
 * change in Shiprocket's docs is a .env edit, not a code change.
 */
class FastrrCheckoutService
{
    /**
     * Real variants sit above this offset so they can never collide with the
     * synthetic "whole product" variant, which just reuses the product id.
     */
    public const VARIANT_OFFSET = 1_000_000;

    public function isConfigured(): bool
    {
        return filled(config('services.fastrr.api_key')) && filled(config('services.fastrr.api_secret'));
    }

    public function scriptUrl(): string
    {
        return (string) config('services.fastrr.script_url');
    }

    /**
     * Base64(HMAC-SHA256(rawBody, secret)) — exactly what Fastrr expects in
     * the X-Api-HMAC-SHA256 header and what it sends back on webhooks.
     */
    public function signature(string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, (string) config('services.fastrr.api_secret'), true));
    }

    public function verifySignature(string $rawBody, ?string $given): bool
    {
        return filled($given) && hash_equals($this->signature($rawBody), trim($given));
    }

    /**
     * Ask Fastrr for a checkout token for one line. Prefills the customer
     * so the iframe lands straight on payment for a logged-in shopper whose
     * address we already hold.
     *
     * @throws RuntimeException when Fastrr is unreachable or answers without a token
     */
    public function accessToken(Product $product, ?ProductVariant $variant, int $quantity, User $user, Address $address, string $redirectUrl): string
    {
        $payload = [
            'cart_data' => [
                'items' => [[
                    'variant_id' => (string) $this->variantIdFor($product, $variant),
                    'quantity' => $quantity,
                ]],
            ],
            'redirect_url' => $redirectUrl,
            'timestamp' => now()->toIso8601String(),
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $address->phone ?: $user->phone,
                'address' => [
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'pincode' => $address->postal_code,
                    'country' => $address->country ?: 'India',
                ],
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Api-Key' => (string) config('services.fastrr.api_key'),
                'X-Api-HMAC-SHA256' => $this->signature($body),
            ])
            ->withBody($body, 'application/json')
            ->post(rtrim((string) config('services.fastrr.base_url'), '/').config('services.fastrr.token_path'));

        if ($response->failed()) {
            Log::warning('Fastrr access-token request failed', [
                'status' => $response->status(),
                'body' => str($response->body())->limit(300)->toString(),
            ]);

            throw new RuntimeException('Fastrr checkout is unavailable right now.');
        }

        $token = data_get($response->json(), config('services.fastrr.token_key'))
            ?? data_get($response->json(), 'data.token')
            ?? data_get($response->json(), 'access_token')
            ?? data_get($response->json(), 'result.token');

        if (! is_string($token) || $token === '') {
            Log::warning('Fastrr access-token response carried no token', ['body' => str($response->body())->limit(300)->toString()]);

            throw new RuntimeException('Fastrr checkout is unavailable right now.');
        }

        return $token;
    }

    public function variantIdFor(Product $product, ?ProductVariant $variant): int
    {
        return $variant ? self::VARIANT_OFFSET + $variant->id : $product->id;
    }

    /**
     * @return array{0: Product, 1: ?ProductVariant}|null
     */
    public function resolveVariant(int|string $variantId): ?array
    {
        $id = (int) $variantId;

        if ($id > self::VARIANT_OFFSET) {
            $variant = ProductVariant::with('product')->find($id - self::VARIANT_OFFSET);

            return $variant?->product ? [$variant->product, $variant] : null;
        }

        $product = Product::find($id);

        return $product ? [$product, null] : null;
    }
}
