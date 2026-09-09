<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'balance' => $this->wallet_balance,
        ];
    }
}
