<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\CustomerOldJewelleryFinalized;
use App\Notifications\VendorInvitedToBid;
use App\Services\OldJewellery\OldJewelleryWalletService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_inviting_vendors_notifies_each_one(): void
    {
        Notification::fake();

        // VendorInvitationService::inviteAll() also notifies every
        // super_admin — that role must exist for User::role('super_admin')
        // to resolve (Spatie throws RoleDoesNotExist otherwise). Production
        // environments seed it via ShieldSeeder; tests seed it here, same as
        // AdminApiTest::makeAdmin() in this directory.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000120',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000120', 'email' => 'v@example.com', 'is_active' => true]);

        app(VendorInvitationService::class)->inviteAll($request);

        Notification::assertSentTo($vendor, VendorInvitedToBid::class);
    }

    public function test_vendor_with_whatsapp_number_receives_whatsapp_channel(): void
    {
        Notification::fake();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000123',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $vendor = Vendor::create([
            'name' => 'V', 'mobile' => '9000000123', 'email' => 'v123@example.com',
            'whatsapp_number' => '9000000123', 'is_active' => true,
        ]);

        app(VendorInvitationService::class)->inviteAll($request);

        Notification::assertSentTo(
            $vendor,
            VendorInvitedToBid::class,
            function ($notification, $channels) {
                return in_array(WhatsAppChannel::class, $channels, true) && in_array('mail', $channels, true);
            },
        );
    }

    public function test_vendor_without_whatsapp_number_gets_mail_only(): void
    {
        Notification::fake();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000124',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $vendor = Vendor::create([
            'name' => 'V', 'mobile' => '9000000124', 'email' => 'v124@example.com', 'is_active' => true,
        ]);

        app(VendorInvitationService::class)->inviteAll($request);

        Notification::assertSentTo(
            $vendor,
            VendorInvitedToBid::class,
            function ($notification, $channels) {
                return $channels === ['mail'];
            },
        );
    }

    public function test_vendor_with_null_email_does_not_throw_and_does_not_block_other_vendors(): void
    {
        Notification::fake();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000125',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $noEmailVendor = Vendor::create([
            'name' => 'No Email', 'mobile' => '9000000125', 'email' => null, 'is_active' => true,
        ]);
        $otherVendor = Vendor::create([
            'name' => 'Has Email', 'mobile' => '9000000126', 'email' => 'other@example.com', 'is_active' => true,
        ]);

        app(VendorInvitationService::class)->inviteAll($request);

        Notification::assertSentTo($otherVendor, VendorInvitedToBid::class);
        // The null-email vendor still gets a notification record dispatched
        // (via() still resolves) — the important guarantee is that nothing
        // thrown for it prevented the other vendor above from being
        // notified.
        $this->assertTrue(true);
    }

    public function test_wallet_credit_notifies_customer(): void
    {
        Notification::fake();

        $user = User::factory()->create(['wallet_balance' => 0]);
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000121',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);
        $bid = \App\Models\OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now(),
        ]);
        $request->update(['winning_bid_id' => $bid->id, 'final_amount' => 1000, 'status' => 'bid_selected']);

        app(OldJewelleryWalletService::class)->creditForRequest($request->fresh());

        Notification::assertSentTo($user, CustomerOldJewelleryFinalized::class);
    }

    public function test_wallet_credit_does_not_renotify_when_already_credited(): void
    {
        Notification::fake();

        $user = User::factory()->create(['wallet_balance' => 0]);
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000122',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);
        $bid = \App\Models\OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now(),
        ]);
        $request->update(['winning_bid_id' => $bid->id, 'final_amount' => 1000, 'status' => 'bid_selected']);

        // Freeze the argument creditForRequest() receives *before* wiring the
        // race-simulation listener below — creditForRequest()'s own outer
        // pre-transaction check (line ~30) reads $request->id directly off
        // this object without re-querying OldJewelleryRequest, so it never
        // fires a retrieved event and is unaffected by the listener. Only
        // the inner lockForUpdate()->first() re-query inside DB::transaction()
        // does.
        $argument = $request->fresh();

        // Simulate a concurrent/overlapping caller winning the race: insert
        // the OldJewelleryWalletCredit row (as if another process's call
        // already completed) at the exact moment creditForRequest()'s
        // transaction closure re-checks for it under the lock — i.e. *after*
        // this call's own outer pre-transaction check already found nothing,
        // so it proceeds into DB::transaction() and only then discovers the
        // row exists via the inner re-check at
        // OldJewelleryWalletService::creditForRequest()'s lockForUpdate()
        // re-check. That inner-re-check return path is exactly the one the
        // wasRecentlyCreated gate must not re-notify from.
        OldJewelleryRequest::retrieved(function (OldJewelleryRequest $retrieved) use ($request) {
            if ($retrieved->id !== $request->id || OldJewelleryWalletCredit::where('old_jewellery_request_id', $request->id)->exists()) {
                return;
            }

            $walletTransaction = WalletTransaction::create([
                'user_id' => $request->user_id,
                'type' => 'credit',
                'amount' => 900,
                'balance_after' => 900,
                'reason' => 'old_jewellery_sale',
            ]);

            OldJewelleryWalletCredit::create([
                'user_id' => $request->user_id,
                'old_jewellery_request_id' => $request->id,
                'wallet_transaction_id' => $walletTransaction->id,
                'gross_amount' => 1000,
                'deduction_amount' => 100,
                'credited_amount' => 900,
                'remaining_amount' => 900,
                'credited_at' => now(),
                'expires_at' => now()->addDays(10),
                'status' => 'active',
            ]);
        });

        app(OldJewelleryWalletService::class)->creditForRequest($argument);

        Notification::assertNotSentTo($user, CustomerOldJewelleryFinalized::class);
    }
}
