<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryRequest', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:OldJewelleryRequest', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:OldJewelleryRequest', 'Update:OldJewelleryRequest']);
        $admin->assignRole($role);

        return $admin;
    }

    public function test_admin_can_submit_a_bid(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000100',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 950]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('old_jewellery_bids', ['bidder_type' => 'admin', 'amount' => '950.00']);
    }

    public function test_admin_cannot_submit_bid_above_ceiling(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000105',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 1000001]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('old_jewellery_bids', ['amount' => '1000001.00']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000101',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 950]);

        $response->assertStatus(403);
    }

    public function test_admin_close_selects_highest_bid(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000102',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 700,
            'submitted_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/close");

        $response->assertOk()->assertJsonPath('data.status', 'wallet_credited');
    }

    public function test_admin_close_before_deadline_still_force_closes(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000104',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 700,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/close");

        $response->assertOk()->assertJsonPath('data.status', 'wallet_credited');
        $this->assertSame('wallet_credited', $request->fresh()->status);
    }

    public function test_admin_bid_after_deadline_returns_409(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000103',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(4),
            'bidding_end_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 950]);

        $response->assertStatus(409);
    }
}
