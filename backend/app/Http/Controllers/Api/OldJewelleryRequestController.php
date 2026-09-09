<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreOldJewelleryRequestRequest;
use App\Http\Resources\OldJewelleryRequestResource;
use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryRequestService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OldJewelleryRequestController extends ApiController
{
    public function __construct(
        private readonly OldJewelleryRequestService $requestService,
        private readonly VendorInvitationService $invitationService,
    ) {}

    public function store(StoreOldJewelleryRequestRequest $request)
    {
        try {
            $oldJewelleryRequest = $this->requestService->create(
                $request->user(),
                ['description' => $request->validated('description')],
                $request->file('image'),
                $request->file('video'),
            );
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors());
        }

        // Vendor invitations are created synchronously right after submission
        // (spec: "request created -> vendors notified" happens as part of the
        // same submission flow, not a separate manual admin step). Actual
        // notification SENDING is queued (Task 13) — invitation-row creation
        // itself stays synchronous and fast (no external I/O).
        $this->invitationService->inviteAll($oldJewelleryRequest->fresh());

        return $this->success(
            new OldJewelleryRequestResource($oldJewelleryRequest->fresh()),
            'Old jewellery request created successfully.',
            201,
        );
    }

    public function index(Request $request)
    {
        $requests = $request->user()
            ->oldJewelleryRequests()
            ->latest()
            ->paginate(15);

        return $this->success(OldJewelleryRequestResource::collection($requests));
    }

    public function show(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('view', $oldJewelleryRequest);

        return $this->success(new OldJewelleryRequestResource($oldJewelleryRequest));
    }

    public function status(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('view', $oldJewelleryRequest);

        return $this->success([
            'request_number' => $oldJewelleryRequest->request_number,
            'status' => $oldJewelleryRequest->status,
            'bidding_end_at' => $oldJewelleryRequest->bidding_end_at?->toIso8601String(),
        ]);
    }
}
