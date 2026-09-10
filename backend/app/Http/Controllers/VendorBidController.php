<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\VendorAcceptBidRequest;
use App\Http\Requests\Api\VendorDeclineRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VendorBidController extends Controller
{
    public function __construct(private readonly OldJewelleryBiddingService $biddingService) {}

    public function show(string $token): View
    {
        $invitation = $this->resolveInvitation($token);

        if (! $invitation) {
            return view('vendor.errors.link-invalid');
        }

        return view('vendor.old-jewellery-bid', ['invitation' => $invitation->load('request')]);
    }

    public function accept(VendorAcceptBidRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);

        if (! $invitation) {
            return redirect()->route('old-jewellery.vendor.show', $token);
        }

        try {
            $this->biddingService->accept($invitation, (float) $request->validated('amount'));
        } catch (\DomainException $e) {
            return redirect()->route('old-jewellery.vendor.show', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('old-jewellery.vendor.show', $token)->with('success', 'Your bid has been submitted.');
    }

    public function decline(VendorDeclineRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);

        if (! $invitation) {
            return redirect()->route('old-jewellery.vendor.show', $token);
        }

        try {
            $this->biddingService->decline($invitation, $request->validated('reason'));
        } catch (\DomainException $e) {
            return redirect()->route('old-jewellery.vendor.show', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('old-jewellery.vendor.show', $token)->with('success', 'Your response has been recorded.');
    }

    /**
     * Resolves the token the same way VendorTokenAuth does for the API route,
     * but returns null instead of a JSON 404/403 response — this controller
     * renders an HTML error view on failure instead (see link-invalid.blade.php),
     * since VendorTokenAuth's failure responses are hard-coded JSON and are not
     * reused here. See spec §2 "Problem with reusing vendor.token middleware directly".
     */
    private function resolveInvitation(string $token): ?OldJewelleryVendorInvitation
    {
        $invitation = $this->biddingService->findInvitationByToken($token);

        if (! $invitation || now()->greaterThanOrEqualTo($invitation->expires_at)) {
            return null;
        }

        return $invitation;
    }
}
