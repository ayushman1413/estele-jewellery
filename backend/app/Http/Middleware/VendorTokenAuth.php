<?php

namespace App\Http\Middleware;

use App\Services\OldJewellery\OldJewelleryBiddingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {token} route parameter into an OldJewelleryVendorInvitation
 * and rejects the request before it ever reaches a controller if the token
 * doesn't match any invitation or its bidding window has expired. Vendors
 * have no account/session — this middleware is their entire authorization
 * boundary, so it never relies on the URL alone: the token is checked
 * against a stored hash, not compared as a literal path segment.
 */
class VendorTokenAuth
{
    public function __construct(private readonly OldJewelleryBiddingService $biddingService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        $invitation = $token ? $this->biddingService->findInvitationByToken($token) : null;

        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or unrecognized vendor link.',
            ], 404);
        }

        if (now()->greaterThanOrEqualTo($invitation->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'This vendor link has expired.',
            ], 403);
        }

        $request->attributes->set('old_jewellery_invitation', $invitation);

        return $next($request);
    }
}
