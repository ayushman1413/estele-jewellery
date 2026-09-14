<?php

namespace App\Http\Middleware;

use App\Services\Payment\FastrrCheckoutService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints Fastrr calls into us (catalogue feeds and the order
 * webhook). A request is accepted when it carries a valid HMAC of its body
 * under our API secret, or the shared catalogue token from .env. Nothing
 * else creates orders or reads the catalogue feed.
 */
class VerifyFastrrRequest
{
    public function __construct(private readonly FastrrCheckoutService $fastrr) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hmac = $request->header('X-Api-HMAC-SHA256');

        if ($hmac && $this->fastrr->verifySignature($request->getContent(), $hmac)) {
            return $next($request);
        }

        $token = config('services.fastrr.catalog_token');
        $given = $request->header('X-Api-Key') ?? $request->bearerToken() ?? $request->query('token');

        if (filled($token) && filled($given) && hash_equals((string) $token, (string) $given)) {
            return $next($request);
        }

        return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }
}
