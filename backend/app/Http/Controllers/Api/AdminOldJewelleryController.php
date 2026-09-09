<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AdminSubmitBidRequest;
use App\Http\Resources\OldJewelleryAdminResource;
use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOldJewelleryController extends ApiController
{
    public function __construct(
        private readonly OldJewelleryBiddingService $biddingService,
        private readonly OldJewelleryClosingService $closingService,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        $requests = OldJewelleryRequest::with(['user'])->latest()->paginate(15);

        return $this->success(OldJewelleryAdminResource::collection($requests));
    }

    public function show(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        return $this->success(new OldJewelleryAdminResource(
            $oldJewelleryRequest->load(['user', 'invitations.vendor', 'bids.vendor', 'bids.adminUser']),
        ));
    }

    public function bids(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        return $this->success(new OldJewelleryAdminResource(
            $oldJewelleryRequest->load(['bids.vendor', 'bids.adminUser']),
        ));
    }

    public function bid(AdminSubmitBidRequest $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('bidAsAdmin', OldJewelleryRequest::class);

        try {
            $this->biddingService->submitAdminBid(
                $oldJewelleryRequest,
                $request->user(),
                (float) $request->validated('amount'),
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), [], 409);
        }

        return $this->success(
            new OldJewelleryAdminResource($oldJewelleryRequest->fresh()->load(['bids.vendor', 'bids.adminUser'])),
            'Bid submitted.',
        );
    }

    public function close(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        $result = $this->closingService->close($oldJewelleryRequest, force: true);

        return $this->success(new OldJewelleryAdminResource($result), 'Closing processed.');
    }
}
