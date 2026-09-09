<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing view — the only resource allowed to show all vendor
 * responses, all bid amounts, and the admin's own bid together in one
 * payload.
 */
class OldJewelleryAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'request_number' => $this->request_number,
            'customer' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ],
            'description' => $this->description,
            'status' => $this->status,
            'bidding_start_at' => $this->bidding_start_at?->toIso8601String(),
            'bidding_end_at' => $this->bidding_end_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'final_amount' => $this->final_amount,
            'deduction_amount' => $this->deduction_amount,
            'credited_amount' => $this->credited_amount,
            'winning_bid_id' => $this->winning_bid_id,
            'image_url' => $this->getFirstMediaUrl('image', 'thumb') ?: null,
            'video_url' => $this->hasMedia('video') ? $this->getFirstMediaUrl('video') : null,
            'invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'vendor_id' => $invitation->vendor_id,
                'vendor_name' => $invitation->vendor->name,
                'response_status' => $invitation->response_status,
                'decline_reason' => $invitation->decline_reason,
                'responded_at' => $invitation->responded_at?->toIso8601String(),
            ])),
            'bids' => $this->whenLoaded('bids', fn () => $this->bids->map(fn ($bid) => [
                'id' => $bid->id,
                'bidder_type' => $bid->bidder_type,
                'vendor_name' => $bid->vendor?->name,
                'admin_name' => $bid->adminUser?->name,
                'amount' => $bid->amount,
                'is_valid' => $bid->is_valid,
                'submitted_at' => $bid->submitted_at?->toIso8601String(),
            ])),
        ];
    }
}
