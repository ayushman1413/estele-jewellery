<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(): OldJewelleryRequest
    {
        return OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000010',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
    }

    public function test_invites_only_active_vendors(): void
    {
        $request = $this->makeRequest();

        $active = Vendor::create(['name' => 'Active Co', 'mobile' => '9000000010', 'is_active' => true]);
        Vendor::create(['name' => 'Inactive Co', 'mobile' => '9000000011', 'is_active' => false]);

        $results = app(VendorInvitationService::class)->inviteAll($request);

        $this->assertCount(1, $results);
        $this->assertSame($active->id, $results->first()['invitation']->vendor_id);
        // inviteAll() transitions straight to 'bidding_active' (not the
        // intermediate 'vendors_notified') so that
        // CloseExpiredOldJewelleryBiddingJob's status = 'bidding_active'
        // filter actually matches this request once its bidding window ends.
        $this->assertSame('bidding_active', $request->fresh()->status);
    }

    public function test_token_is_stored_only_as_a_hash(): void
    {
        $request = $this->makeRequest();
        Vendor::create(['name' => 'Active Co', 'mobile' => '9000000012', 'is_active' => true]);

        $results = app(VendorInvitationService::class)->inviteAll($request);
        $result = $results->first();

        $this->assertNotEmpty($result['plaintext_token']);
        $this->assertSame(
            hash('sha256', $result['plaintext_token']),
            $result['invitation']->token_hash,
        );
        $this->assertDatabaseMissing('old_jewellery_vendor_invitations', [
            'token_hash' => $result['plaintext_token'],
        ]);
    }

    public function test_inviting_twice_does_not_duplicate_invitations(): void
    {
        $request = $this->makeRequest();
        Vendor::create(['name' => 'Active Co', 'mobile' => '9000000013', 'is_active' => true]);

        $service = app(VendorInvitationService::class);
        $service->inviteAll($request);
        $service->inviteAll($request->fresh());

        $this->assertSame(1, $request->fresh()->invitations()->count());
    }

    public function test_invitation_expires_at_matches_bidding_end_at(): void
    {
        $request = $this->makeRequest();
        Vendor::create(['name' => 'Active Co', 'mobile' => '9000000014', 'is_active' => true]);

        $results = app(VendorInvitationService::class)->inviteAll($request);

        $this->assertSame(
            $request->bidding_end_at->timestamp,
            $results->first()['invitation']->expires_at->timestamp,
        );
    }
}
