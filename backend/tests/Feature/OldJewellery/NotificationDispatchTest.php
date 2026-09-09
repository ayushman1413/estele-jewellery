<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Models\Vendor;
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

        Notification::assertSentOnDemand(VendorInvitedToBid::class);
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
}
