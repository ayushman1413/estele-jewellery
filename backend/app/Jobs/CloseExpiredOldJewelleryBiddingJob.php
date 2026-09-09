<?php

namespace App\Jobs;

use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotency comes from the query below (only rows still 'bidding_active'
 * past their deadline are ever touched) plus OldJewelleryClosingService's
 * own row-lock re-check — not from ShouldBeUnique alone, since an
 * overlapping/delayed cron run is an explicit risk this must tolerate.
 */
class CloseExpiredOldJewelleryBiddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(?OldJewelleryClosingService $closingService = null): void
    {
        $closingService ??= app(OldJewelleryClosingService::class);

        OldJewelleryRequest::where('status', 'bidding_active')
            ->where('bidding_end_at', '<=', now())
            ->cursor()
            ->each(fn (OldJewelleryRequest $request) => $closingService->close($request));
    }
}
