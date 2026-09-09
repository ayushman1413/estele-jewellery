<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\AdminNewOldJewelleryRequest;
use App\Notifications\VendorInvitedToBid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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
        $results = DB::transaction(function () use ($request) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if ($locked->invitations()->exists()) {
                return collect();
            }

            $vendors = Vendor::where('is_active', true)->get();

            $created = $vendors->map(function (Vendor $vendor) use ($locked) {
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

            if ($created->isNotEmpty()) {
                // Vendors being notified and the bidding window opening are
                // the same real-world moment — go straight to 'bidding_active'
                // so CloseExpiredOldJewelleryBiddingJob (which filters on
                // status = 'bidding_active') can actually find this request
                // once its bidding window expires. See
                // OldJewelleryRequest::ALLOWED_TRANSITIONS for the transition
                // map this relies on.
                $locked->update(['status' => 'bidding_active']);

                OldJewelleryActivityLog::create([
                    'old_jewellery_request_id' => $locked->id,
                    'actor_type' => 'system',
                    'action' => 'vendors_notified',
                    'from_status' => 'submitted',
                    'to_status' => 'bidding_active',
                    'metadata' => ['vendor_count' => $created->count()],
                ]);
            }

            return $created;
        });

        // Notification dispatch happens outside the DB transaction: a mail/queue
        // failure here must never roll back the invitation rows that were just
        // committed (same "separate business transaction from notification
        // delivery" reasoning as WalletService::credit()'s own placement).
        foreach ($results as $result) {
            try {
                $result['invitation']->vendor->notify(
                    new VendorInvitedToBid($result['invitation'], $result['plaintext_token']),
                );
            } catch (\Throwable $e) {
                // One bad address/transport failure must never abort
                // notification delivery to the remaining vendors in this
                // same batch.
                Log::warning('Failed to notify vendor of old jewellery bid invitation.', [
                    'vendor_id' => $result['invitation']->vendor_id,
                    'old_jewellery_request_id' => $result['invitation']->old_jewellery_request_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Guard against an unseeded super_admin role (fresh/test environments
        // before ShieldSeeder runs): User::role() throws RoleDoesNotExist
        // otherwise, which would incorrectly block the vendor notifications
        // above even though they already succeeded.
        if ($results->isNotEmpty() && Role::where(['name' => 'super_admin', 'guard_name' => 'web'])->exists()) {
            $admins = User::role('super_admin')->whereNotNull('email')->get();
            Notification::send($admins, new AdminNewOldJewelleryRequest($request->fresh()));
        }

        return $results;
    }

    public function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
