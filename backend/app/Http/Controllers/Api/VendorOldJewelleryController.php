<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\VendorAcceptBidRequest;
use App\Http\Requests\Api\VendorDeclineRequest;
use App\Http\Resources\OldJewelleryVendorViewResource;
use App\Models\OldJewelleryVendorInvitation;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use Illuminate\Http\Request;

class VendorOldJewelleryController extends ApiController
{
    public function __construct(private readonly OldJewelleryBiddingService $biddingService) {}

    public function show(Request $request)
    {
        $invitation = $this->invitation($request);

        return $this->success(new OldJewelleryVendorViewResource($invitation->load('request')));
    }

    public function accept(VendorAcceptBidRequest $request)
    {
        $invitation = $this->invitation($request);

        try {
            $this->biddingService->accept($invitation, (float) $request->validated('amount'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), [], 409);
        }

        return $this->success(
            new OldJewelleryVendorViewResource($invitation->fresh()->load('request')),
            'Bid submitted successfully.',
        );
    }

    public function decline(VendorDeclineRequest $request)
    {
        $invitation = $this->invitation($request);

        try {
            $this->biddingService->decline($invitation, $request->validated('reason'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), [], 409);
        }

        return $this->success(
            new OldJewelleryVendorViewResource($invitation->fresh()->load('request')),
            'Response recorded.',
        );
    }

    /**
     * VendorTokenAuth (Task 8) has already validated the token and stashed
     * the resolved invitation on the request — every action here trusts
     * only that attribute, never the raw {token} path segment.
     */
    private function invitation(Request $request): OldJewelleryVendorInvitation
    {
        return $request->attributes->get('old_jewellery_invitation');
    }
}
