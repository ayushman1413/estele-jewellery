<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OldJewelleryWalletCreditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'request_number' => $this->request->request_number,
            'gross_amount' => $this->gross_amount,
            'deduction_amount' => $this->deduction_amount,
            'credited_amount' => $this->credited_amount,
            'remaining_amount' => $this->remaining_amount,
            'credited_at' => $this->credited_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
