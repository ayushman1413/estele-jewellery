<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing view of their own request. Never includes vendor
 * identities, individual bid amounts, or the admin bid — only the
 * customer's own request/status/final outcome.
 */
class OldJewelleryRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'request_number' => $this->request_number,
            'description' => $this->description,
            'status' => $this->status,
            'bidding_start_at' => $this->bidding_start_at?->toIso8601String(),
            'bidding_end_at' => $this->bidding_end_at?->toIso8601String(),
            'final_amount' => $this->final_amount,
            'deduction_amount' => $this->deduction_amount,
            'credited_amount' => $this->credited_amount,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'image_url' => $this->getFirstMediaUrl('image', 'thumb') ?: null,
            'has_video' => $this->hasMedia('video'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
