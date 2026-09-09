<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use Illuminate\Support\Facades\DB;

class OldJewelleryClosingService
{
    public function __construct(private readonly OldJewelleryWalletService $walletService) {}

    /**
     * Idempotent by construction: the lockForUpdate + status/deadline
     * re-check inside the transaction means a second concurrent or delayed
     * call always finds the row already moved past 'bidding_active' and
     * returns without any further effect — no separate "already closed"
     * flag is needed.
     *
     * $force skips ONLY the now() >= bidding_end_at deadline check (for an
     * admin-triggered "close bidding now"). It never skips the
     * status === 'bidding_active' check — that's what keeps a second
     * force-close call, or an overlap with the scheduled job, a safe no-op.
     */
    public function close(OldJewelleryRequest $request, bool $force = false): OldJewelleryRequest
    {
        return DB::transaction(function () use ($request, $force) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if ($locked->status !== 'bidding_active' || (! $force && now()->lessThan($locked->bidding_end_at))) {
                return $locked;
            }

            $locked->update(['status' => 'bidding_closed']);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'bidding_closed',
                'from_status' => 'bidding_active',
                'to_status' => 'bidding_closed',
            ]);

            $winner = OldJewelleryBid::where('old_jewellery_request_id', $locked->id)
                ->where('is_valid', true)
                ->orderByDesc('amount')
                ->orderBy('submitted_at')
                ->first();

            if (! $winner) {
                $locked->update(['status' => 'cancelled']);

                OldJewelleryActivityLog::create([
                    'old_jewellery_request_id' => $locked->id,
                    'actor_type' => 'system',
                    'action' => 'no_valid_bids',
                    'from_status' => 'bidding_closed',
                    'to_status' => 'cancelled',
                ]);

                return $locked->fresh();
            }

            $locked->update([
                'winning_bid_id' => $winner->id,
                'final_amount' => $winner->amount,
                'closed_at' => now(),
                'status' => 'bid_selected',
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'bid_selected',
                'from_status' => 'bidding_closed',
                'to_status' => 'bid_selected',
                'metadata' => [
                    'winning_bid_id' => $winner->id,
                    'amount' => (string) $winner->amount,
                    'bidder_type' => $winner->bidder_type,
                ],
            ]);

            $this->walletService->creditForRequest($locked->fresh());

            return $locked->fresh();
        });
    }
}
