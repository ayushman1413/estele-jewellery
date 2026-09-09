<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewelleryBiddingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequestWithInvitation(bool $expired = false): array
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000020',
            'status' => 'vendors_notified',
            'bidding_start_at' => $expired ? now()->subHours(4) : now(),
            'bidding_end_at' => $expired ? now()->subHours(1) : now()->addHours(3),
        ]);

        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000020', 'is_active' => true]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => app(VendorInvitationService::class)->hashToken('plain-token'),
            'expires_at' => $request->bidding_end_at,
            'response_status' => 'pending',
        ]);

        return [$request, $vendor, $invitation];
    }

    public function test_find_invitation_by_token_matches_hash(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        $found = app(OldJewelleryBiddingService::class)->findInvitationByToken('plain-token');

        $this->assertTrue($found->is($invitation));
    }

    public function test_find_invitation_by_wrong_token_returns_null(): void
    {
        $this->makeRequestWithInvitation();

        $found = app(OldJewelleryBiddingService::class)->findInvitationByToken('wrong-token');

        $this->assertNull($found);
    }

    public function test_accept_creates_a_bid_and_marks_invitation_accepted(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        $bid = app(OldJewelleryBiddingService::class)->accept($invitation, 800);

        $this->assertSame('800.00', $bid->amount);
        $this->assertSame('vendor', $bid->bidder_type);
        $this->assertSame('accepted', $invitation->fresh()->response_status);
    }

    public function test_accept_rejects_non_positive_amount(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->accept($invitation, 0);
    }

    public function test_accept_rejects_after_deadline_even_if_scheduler_has_not_run(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation(expired: true);

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->accept($invitation, 500);
    }

    public function test_accept_rejects_a_second_response_from_same_invitation(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        app(OldJewelleryBiddingService::class)->accept($invitation, 500);

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->accept($invitation->fresh(), 600);
    }

    public function test_decline_marks_invitation_declined_with_reason(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        app(OldJewelleryBiddingService::class)->decline($invitation, 'Too damaged');

        $invitation->refresh();
        $this->assertSame('declined', $invitation->response_status);
        $this->assertSame('Too damaged', $invitation->decline_reason);
        $this->assertDatabaseMissing('old_jewellery_bids', ['invitation_id' => $invitation->id]);
    }

    public function test_admin_bid_creates_a_bid_row(): void
    {
        [$request] = $this->makeRequestWithInvitation();
        $admin = User::factory()->create();

        $bid = app(OldJewelleryBiddingService::class)->submitAdminBid($request, $admin, 950);

        $this->assertSame('admin', $bid->bidder_type);
        $this->assertSame('950.00', $bid->amount);
        $this->assertSame($admin->id, $bid->admin_user_id);
    }

    public function test_admin_bid_rejected_after_deadline(): void
    {
        [$request] = $this->makeRequestWithInvitation(expired: true);
        $admin = User::factory()->create();

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->submitAdminBid($request, $admin, 950);
    }
}
