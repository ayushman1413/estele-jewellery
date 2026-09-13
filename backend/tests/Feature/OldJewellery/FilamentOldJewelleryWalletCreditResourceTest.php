<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentOldJewelleryWalletCreditResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_the_wallet_credits_index(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryWalletCredit', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:OldJewelleryWalletCredit');
        $admin->assignRole($role);

        $response = $this->actingAs($admin)->get('/admin/old-jewellery-wallet-credits');

        $response->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'marketing', 'guard_name' => 'web']);
        $user->assignRole('marketing'); // panel-access role with no permissions synced — super_admin bypasses the gate

        $response = $this->actingAs($user)->get('/admin/old-jewellery-wallet-credits');

        $response->assertForbidden();
    }

    public function test_index_shows_credit_rows_with_expiry_status(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryWalletCredit', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:OldJewelleryWalletCredit');
        $admin->assignRole($role);

        $customer = User::factory()->create(['name' => 'Jane Customer']);
        $request = OldJewelleryRequest::create([
            'request_number' => 'OJ-TEST-CREDIT-1',
            'user_id' => $customer->id,
            'status' => 'completed',
            'bidding_start_at' => now()->subDays(5),
            'bidding_end_at' => now()->subDays(5)->addHours(3),
        ]);
        $walletTransaction = WalletTransaction::create([
            'user_id' => $customer->id,
            'type' => 'credit',
            'amount' => 9000,
            'balance_after' => 9000,
            'reason' => 'old_jewellery_sale',
        ]);
        OldJewelleryWalletCredit::create([
            'user_id' => $customer->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $walletTransaction->id,
            'gross_amount' => 10000,
            'deduction_amount' => 1000,
            'credited_amount' => 9000,
            'remaining_amount' => 9000,
            'credited_at' => now()->subDays(5),
            'expires_at' => now()->addDay(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get('/admin/old-jewellery-wallet-credits')
            ->assertOk()
            ->assertSee('OJ-TEST-CREDIT-1')
            ->assertSee('Jane Customer');
    }
}
