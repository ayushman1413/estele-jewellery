<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every mutating method here independently re-checks now() < bidding_end_at
 * — the scheduler-driven close job (Task 7) is a convenience, not the
 * source of truth for whether bidding is still open.
 */
class OldJewelleryBiddingService
{
    public function findInvitationByToken(string $plaintextToken): ?OldJewelleryVendorInvitation
    {
        $hash = app(VendorInvitationService::class)->hashToken($plaintextToken);

        return OldJewelleryVendorInvitation::where('token_hash', $hash)->first();
    }

    public function accept(OldJewelleryVendorInvitation $invitation, float $amount): OldJewelleryBid
    {
        if ($amount <= 0) {
            throw new \DomainException('Bid amount must be positive.');
        }

        return DB::transaction(function () use ($invitation, $amount) {
            $locked = OldJewelleryVendorInvitation::whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked->response_status !== 'pending') {
                throw new \DomainException('This invitation has already been responded to.');
            }

            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                throw new \DomainException('The bidding window for this request has closed.');
            }

            $bid = OldJewelleryBid::create([
                'old_jewellery_request_id' => $locked->old_jewellery_request_id,
                'bidder_type' => 'vendor',
                'vendor_id' => $locked->vendor_id,
                'invitation_id' => $locked->id,
                'amount' => $amount,
                'submitted_at' => now(),
            ]);

            $locked->update([
                'response_status' => 'accepted',
                'responded_at' => now(),
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->old_jewellery_request_id,
                'actor_type' => 'vendor',
                'actor_id' => $locked->vendor_id,
                'action' => 'vendor_bid_submitted',
                'metadata' => ['amount' => $amount],
            ]);

            return $bid;
        });
    }

    public function decline(OldJewelleryVendorInvitation $invitation, ?string $reason): OldJewelleryVendorInvitation
    {
        return DB::transaction(function () use ($invitation, $reason) {
            $locked = OldJewelleryVendorInvitation::whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked->response_status !== 'pending') {
                throw new \DomainException('This invitation has already been responded to.');
            }

            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                throw new \DomainException('The bidding window for this request has closed.');
            }

            $locked->update([
                'response_status' => 'declined',
                'decline_reason' => $reason,
                'responded_at' => now(),
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->old_jewellery_request_id,
                'actor_type' => 'vendor',
                'actor_id' => $locked->vendor_id,
                'action' => 'vendor_declined',
                'metadata' => ['reason' => $reason],
            ]);

            return $locked;
        });
    }

    public function submitAdminBid(OldJewelleryRequest $request, User $admin, float $amount): OldJewelleryBid
    {
        if ($amount <= 0) {
            throw new \DomainException('Bid amount must be positive.');
        }

        return DB::transaction(function () use ($request, $admin, $amount) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if (now()->greaterThanOrEqualTo($locked->bidding_end_at)) {
                throw new \DomainException('The bidding window for this request has closed.');
            }

            $bid = OldJewelleryBid::updateOrCreate(
                [
                    'old_jewellery_request_id' => $locked->id,
                    'bidder_type' => 'admin',
                    'admin_user_id' => $admin->id,
                ],
                [
                    'amount' => $amount,
                    'submitted_at' => now(),
                ],
            );

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'admin',
                'actor_id' => $admin->id,
                'action' => 'admin_bid_submitted',
                'metadata' => ['amount' => $amount],
            ]);

            return $bid;
        });
    }
}
