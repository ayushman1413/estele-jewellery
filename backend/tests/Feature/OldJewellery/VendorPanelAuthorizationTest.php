<?php

namespace Tests\Feature\OldJewellery;

use App\Filament\Resources\OldJewelleryRequests\Pages\ViewOldJewelleryRequest;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A vendor's panel login only ever holds ViewAny/View:OldJewelleryRequest
 * (migration 2026_09_12_120100) — read-only, scoped by invitation. Neither
 * "Submit Valuation" nor "Close Bidding Now" is a read, and the row-scoping
 * that limits *which* requests a vendor sees does nothing to hide the fields
 * on a request it was legitimately invited to.
 */
class VendorPanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsVendor(): array
    {
        $this->seed(ShieldSeeder::class);

        $vendor = Vendor::create([
            'name' => 'Invited Vendor',
            'mobile' => '9000000090',
            'is_active' => true,
            'access_role' => Vendor::ACCESS_ROLE_VENDOR,
        ]);
        $user = User::factory()->create();
        $vendor->update(['user_id' => $user->id]);
        $user->assignRole('vendor');
        $this->actingAs($user);

        return [$user, $vendor];
    }

    private function makeInvitedRequest(Vendor $vendor, string $status = 'bidding_active'): OldJewelleryRequest
    {
        $customer = User::factory()->create();
        $otherVendor = Vendor::create(['name' => 'Other Vendor', 'mobile' => '9000000091', 'is_active' => true]);

        $request = OldJewelleryRequest::create([
            'user_id' => $customer->id,
            'request_number' => 'OJ-AUTHZ-1',
            'status' => $status,
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'mine'),
            'expires_at' => $request->bidding_end_at,
        ]);
        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $otherVendor->id,
            'token_hash' => hash('sha256', 'theirs'),
            'expires_at' => $request->bidding_end_at,
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $otherVendor->id,
            'amount' => 5000,
            'submitted_at' => now(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'admin_user_id' => User::factory()->create()->id,
            'amount' => 9999,
            'submitted_at' => now(),
        ]);

        return $request;
    }

    public function test_a_vendor_login_cannot_submit_an_admin_valuation(): void
    {
        [, $vendor] = $this->actingAsVendor();
        $request = $this->makeInvitedRequest($vendor);

        Livewire::test(ViewOldJewelleryRequest::class, ['record' => $request->request_number])
            ->assertActionHidden('admin_bid');

        $this->assertDatabaseMissing('old_jewellery_bids', [
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'amount' => 1234,
        ]);
    }

    public function test_a_vendor_login_cannot_force_close_bidding(): void
    {
        [, $vendor] = $this->actingAsVendor();
        $request = $this->makeInvitedRequest($vendor);

        Livewire::test(ViewOldJewelleryRequest::class, ['record' => $request->request_number])
            ->assertActionHidden('close_bidding');

        $this->assertSame('bidding_active', $request->fresh()->status);
    }

    public function test_a_vendor_login_does_not_see_other_bids_admin_bid_or_customer_contact_info(): void
    {
        [, $vendor] = $this->actingAsVendor();
        $request = $this->makeInvitedRequest($vendor);
        $request->load('user');

        $response = $this->get("/admin/old-jewellery-requests/{$request->request_number}");

        $response->assertOk()
            ->assertDontSee('Other Vendor')
            ->assertDontSee('₹9,999.00')  // admin bid
            ->assertDontSee('₹5,000.00')  // other vendor's bid
            ->assertDontSee($request->user->phone)
            ->assertDontSee($request->user->email ?? 'no-such-marker-xyz');
    }

    public function test_a_super_admin_still_sees_full_detail_on_the_same_request(): void
    {
        $admin = User::factory()->create();
        $this->seed(ShieldSeeder::class);
        $admin->assignRole('super_admin');

        $vendor = Vendor::create(['name' => 'Some Vendor', 'mobile' => '9000000092', 'is_active' => true]);
        $request = $this->makeInvitedRequest($vendor);

        $this->actingAs($admin)
            ->get("/admin/old-jewellery-requests/{$request->request_number}")
            ->assertOk()
            ->assertSee('Other Vendor')
            ->assertSee('₹9,999.00');
    }

    public function test_the_admin_media_route_refuses_a_vendor_not_invited_to_the_request(): void
    {
        [, $vendor] = $this->actingAsVendor();

        $customer = User::factory()->create();
        $request = OldJewelleryRequest::create([
            'user_id' => $customer->id,
            'request_number' => 'OJ-AUTHZ-2',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        // Note: this vendor was never invited to OJ-AUTHZ-2.

        $this->get(route('admin.old-jewellery.video', $request))->assertForbidden();
        $this->get(route('admin.old-jewellery.image', $request))->assertForbidden();
    }
}
