<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentVendorPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_page_shows_performance_numbers(): void
    {
        $admin = $this->makeAdmin();
        $vendor = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true]);

        $requestOne = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-TEST-PERF-1',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $requestTwo = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-TEST-PERF-2',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $requestOne->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'a'),
            'expires_at' => $requestOne->bidding_end_at,
            'response_status' => 'accepted',
        ]);
        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $requestTwo->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'b'),
            'expires_at' => $requestTwo->bidding_end_at,
            'response_status' => 'declined',
        ]);

        $winningBid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $requestOne->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $vendor->id,
            'amount' => 5000,
            'submitted_at' => now(),
        ]);
        $requestOne->update(['winning_bid_id' => $winningBid->id]);

        $response = $this->actingAs($admin)->get("/admin/vendors/{$vendor->id}");

        $response->assertOk()
            ->assertSee('2') // invitations sent
            ->assertSee('1') // bids won
            ->assertSee('100%'); // win rate: 1 bid submitted, 1 won
    }

    public function test_mobile_verified_column_appears_on_the_list(): void
    {
        $admin = $this->makeAdmin();
        Vendor::create(['name' => 'Unverified Co', 'mobile' => '9222222222', 'is_active' => true]);

        $this->actingAs($admin)
            ->get('/admin/vendors')
            ->assertOk()
            ->assertSee('Verified');
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:Vendor', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:Vendor', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:Vendor', 'View:Vendor']);
        $admin->assignRole($role);

        return $admin;
    }
}
