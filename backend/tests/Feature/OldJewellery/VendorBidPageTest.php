<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorBidPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_renders_the_bid_page(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $this->get(route('old-jewellery.vendor.show', $token))
            ->assertOk()
            ->assertSee($invitation->request->request_number);
    }

    public function test_invalid_token_renders_the_error_view_not_json(): void
    {
        $this->get(route('old-jewellery.vendor.show', 'not-a-real-token'))
            ->assertOk()
            ->assertViewIs('vendor.errors.link-invalid');
    }

    public function test_expired_invitation_renders_the_error_view(): void
    {
        [$invitation, $token] = $this->makeInvitation(['expires_at' => now()->subHour()]);

        $this->get(route('old-jewellery.vendor.show', $token))
            ->assertOk()
            ->assertViewIs('vendor.errors.link-invalid');
    }

    public function test_vendor_can_accept_with_a_bid_amount(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $this->post(route('old-jewellery.vendor.accept', $token), ['amount' => 12000])
            ->assertRedirect(route('old-jewellery.vendor.show', $token));

        $this->assertSame('accepted', $invitation->fresh()->response_status);
    }

    public function test_vendor_can_decline_with_a_reason(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $this->post(route('old-jewellery.vendor.decline', $token), ['reason' => 'Too low quality'])
            ->assertRedirect(route('old-jewellery.vendor.show', $token));

        $this->assertSame('declined', $invitation->fresh()->response_status);
    }

    public function test_a_second_response_is_rejected_with_a_flashed_error(): void
    {
        [$invitation, $token] = $this->makeInvitation();
        $invitation->update(['response_status' => 'accepted', 'responded_at' => now()]);

        $this->post(route('old-jewellery.vendor.accept', $token), ['amount' => 500])
            ->assertRedirect(route('old-jewellery.vendor.show', $token))
            ->assertSessionHas('error');
    }

    public function test_an_invalid_bid_amount_shows_a_validation_error(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $response = $this->post(route('old-jewellery.vendor.accept', $token), ['amount' => 2000000]);

        $response->assertRedirect(route('old-jewellery.vendor.show', $token));
        $response->assertSessionHasErrors('amount');
        $this->assertSame('pending', $invitation->fresh()->response_status);
    }

    /**
     * @return array{0: OldJewelleryVendorInvitation, 1: string}
     */
    private function makeInvitation(array $overrides = []): array
    {
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'mobile' => '9'.random_int(100000000, 999999999),
            'is_active' => true,
        ]);

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-TEST-'.uniqid(),
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $plaintext = 'test-plaintext-token-'.uniqid();

        $invitation = OldJewelleryVendorInvitation::create(array_merge([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => app(VendorInvitationService::class)->hashToken($plaintext),
            'expires_at' => $request->bidding_end_at,
            'response_status' => 'pending',
        ], $overrides));

        return [$invitation, $plaintext];
    }
}
