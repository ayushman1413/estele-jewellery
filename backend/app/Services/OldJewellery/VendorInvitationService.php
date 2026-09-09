<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VendorInvitationService
{
    /**
     * Creates one invitation per active vendor and returns the plaintext
     * tokens for the caller to hand to the notification dispatcher — the
     * plaintext is never persisted, so this is the only place it ever exists
     * outside of the notification payload itself.
     *
     * Idempotent: if this request already has invitations, returns them
     * without creating duplicates (and without re-exposing plaintext
     * tokens for already-created rows, since those are unrecoverable by
     * design — a second call after a partial failure should not attempt to
     * re-notify vendors that already have a row).
     *
     * @return Collection<int, array{invitation: OldJewelleryVendorInvitation, plaintext_token: string}>
     */
    public function inviteAll(OldJewelleryRequest $request): Collection
    {
        return DB::transaction(function () use ($request) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if ($locked->invitations()->exists()) {
                return collect();
            }

            $vendors = Vendor::where('is_active', true)->get();

            $results = $vendors->map(function (Vendor $vendor) use ($locked) {
                $plaintext = Str::random(64);

                $invitation = OldJewelleryVendorInvitation::create([
                    'old_jewellery_request_id' => $locked->id,
                    'vendor_id' => $vendor->id,
                    'token_hash' => $this->hashToken($plaintext),
                    'expires_at' => $locked->bidding_end_at,
                    'response_status' => 'pending',
                ]);

                return ['invitation' => $invitation, 'plaintext_token' => $plaintext];
            });

            if ($results->isNotEmpty()) {
                $locked->update(['status' => 'vendors_notified']);

                OldJewelleryActivityLog::create([
                    'old_jewellery_request_id' => $locked->id,
                    'actor_type' => 'system',
                    'action' => 'vendors_notified',
                    'from_status' => 'submitted',
                    'to_status' => 'vendors_notified',
                    'metadata' => ['vendor_count' => $results->count()],
                ]);
            }

            return $results;
        });
    }

    public function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
