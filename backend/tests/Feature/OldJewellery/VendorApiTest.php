<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(bool $expired = false): array
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000090',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => $expired ? now()->subMinute() : now()->addHours(3),
        ]);

        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000090', 'is_active' => true]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => app(VendorInvitationService::class)->hashToken('vendor-token'),
            'expires_at' => $request->bidding_end_at,
        ]);

        return [$request, $vendor, $invitation];
    }

    public function test_vendor_can_view_via_valid_token(): void
    {
        $this->makeInvitation();

        $response = $this->getJson('/api/v1/vendor/old-jewellery/vendor-token');

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_vendor_can_accept_with_amount(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 800]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('old_jewellery_bids', ['amount' => '800.00']);
    }

    public function test_vendor_cannot_accept_with_zero_amount(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 0]);

        $response->assertStatus(422);
    }

    public function test_vendor_cannot_accept_with_amount_above_ceiling(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 1000001]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('old_jewellery_bids', ['amount' => '1000001.00']);
    }

    public function test_vendor_can_decline(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/decline', ['reason' => 'Too worn']);

        $response->assertOk();
        $this->assertDatabaseHas('old_jewellery_vendor_invitations', ['response_status' => 'declined']);
    }

    public function test_expired_token_returns_403_on_accept(): void
    {
        $this->makeInvitation(expired: true);

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 800]);

        $response->assertStatus(403)->assertJsonPath('success', false);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->makeInvitation();

        $response = $this->getJson('/api/v1/vendor/old-jewellery/wrong-token');

        $response->assertStatus(404);
    }

    public function test_reused_token_after_accept_is_rejected(): void
    {
        $this->makeInvitation();

        $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 800]);
        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 900]);

        $response->assertStatus(409);
    }
}
