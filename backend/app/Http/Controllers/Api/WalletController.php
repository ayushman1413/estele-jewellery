<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\OldJewelleryWalletCreditResource;
use App\Http\Resources\WalletSummaryResource;
use App\Http\Resources\WalletTransactionResource;
use Illuminate\Http\Request;

class WalletController extends ApiController
{
    public function show(Request $request)
    {
        return $this->success(new WalletSummaryResource($request->user()));
    }

    public function transactions(Request $request)
    {
        $transactions = $request->user()->walletTransactions()->latest()->paginate(20);

        return $this->success(WalletTransactionResource::collection($transactions));
    }

    public function oldJewelleryCredits(Request $request)
    {
        $credits = $request->user()
            ->oldJewelleryWalletCredits()
            ->with('request')
            ->latest()
            ->paginate(20);

        return $this->success(OldJewelleryWalletCreditResource::collection($credits));
    }
}
