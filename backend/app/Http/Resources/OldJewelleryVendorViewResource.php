<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Vendor-facing view, scoped to a single invitation. Deliberately excludes:
 * other vendors' names/bids, the admin bid, the customer's identity, and
 * the token hash. This is the only shape a vendor endpoint (Task 10) is
 * allowed to return.
 */
class OldJewelleryVendorViewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ojRequest = $this->request;

        return [
            'request_number' => $ojRequest->request_number,
            'description' => $ojRequest->description,
            'bidding_end_at' => $ojRequest->bidding_end_at?->toIso8601String(),
            'response_status' => $this->response_status,
            'image_url' => $ojRequest->getFirstMediaUrl('image', 'thumb') ?: null,
            'video_url' => $ojRequest->hasMedia('video')
                ? URL::temporarySignedRoute(
                    'old-jewellery.vendor.video',
                    now()->addMinutes(30),
                    ['invitation' => $this->id],
                )
                : null,
        ];
    }
}
