<?php

namespace Tests\Feature\OldJewellery;

use App\Filament\Widgets\OldJewelleryStatsWidget;
use App\Filament\Widgets\VendorPerformanceWidget;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OldJewelleryDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_widget_computes_request_bidding_and_wallet_numbers(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedScenario();

        $stats = collect((new OldJewelleryStatsWidget)->getStatsForTest())
            ->mapWithKeys(fn ($stat) => [$stat->getLabel() => $stat->getValue()]);

        $this->assertSame('3', $stats['Total Requests']);
        $this->assertSame('1', $stats['Pending Requests']);
        $this->assertSame('1', $stats['Active Bidding']);
        $this->assertSame('1', $stats['Completed Requests']);
        $this->assertSame('2', $stats['Vendor Responses']);
        $this->assertSame('₹1,100.00', $stats['Highest Final Bid']);
        $this->assertSame('₹990.00', $stats['Wallet Credited']);
        $this->assertSame('₹0.00', $stats['Expired Wallet Credits']);
        $this->assertSame('100.0%', $stats['Conversion to Purchase']);
    }

    public function test_vendor_performance_widget_lists_vendors_with_win_counts(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedScenario();

        Livewire::test(VendorPerformanceWidget::class)
            ->assertCanSeeTableRecords(Vendor::all())
            ->assertSee('Acme Gold')
            ->assertSee('Bharat Jewels');
    }

    public function test_dashboard_renders_old_jewellery_widgets(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/admin')->assertOk();

        Livewire::test(OldJewelleryStatsWidget::class)
            ->assertSee('Old Jewellery')
            ->assertSee('Total Requests')
            ->assertSee('Conversion to Purchase');

        Livewire::test(VendorPerformanceWidget::class)
            ->assertSee('Vendor Performance');
    }

    private function seedScenario(): void
    {
        $customer = User::factory()->create();
        $vendorA = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true]);
        $vendorB = Vendor::create(['name' => 'Bharat Jewels', 'mobile' => '9222222222', 'is_active' => true]);

        $make = fn (string $number, string $status) => OldJewelleryRequest::create([
            'user_id' => $customer->id, 'request_number' => $number, 'status' => $status,
            'bidding_start_at' => now(), 'bidding_end_at' => now()->addHours(3),
        ]);

        $make('OJ-W-1', 'submitted');
        $make('OJ-W-2', 'bidding_active');
        $done = $make('OJ-W-3', 'bidding_active');

        $inviteA = OldJewelleryVendorInvitation::create(['old_jewellery_request_id' => $done->id, 'vendor_id' => $vendorA->id, 'token_hash' => hash('sha256', 'a'), 'expires_at' => $done->bidding_end_at, 'response_status' => 'accepted']);
        OldJewelleryVendorInvitation::create(['old_jewellery_request_id' => $done->id, 'vendor_id' => $vendorB->id, 'token_hash' => hash('sha256', 'b'), 'expires_at' => $done->bidding_end_at, 'response_status' => 'declined']);
        $win = OldJewelleryBid::create(['old_jewellery_request_id' => $done->id, 'bidder_type' => 'vendor', 'vendor_id' => $vendorA->id, 'invitation_id' => $inviteA->id, 'amount' => 1100, 'submitted_at' => now()]);

        $done->update(['status' => 'bidding_closed']);
        $done->update(['status' => 'bid_selected', 'winning_bid_id' => $win->id, 'final_amount' => 1100, 'deduction_amount' => 110, 'credited_amount' => 990]);
        $done->update(['status' => 'wallet_pending']);
        $done->update(['status' => 'wallet_credited']);

        $tx = WalletTransaction::create(['user_id' => $customer->id, 'type' => 'credit', 'amount' => 990, 'balance_after' => 990, 'reason' => 'old_jewellery_sale']);
        OldJewelleryWalletCredit::create([
            'user_id' => $customer->id, 'old_jewellery_request_id' => $done->id, 'wallet_transaction_id' => $tx->id,
            'gross_amount' => 1100, 'deduction_amount' => 110, 'credited_amount' => 990, 'remaining_amount' => 0,
            'credited_at' => now(), 'expires_at' => now()->addDays(10), 'status' => 'used',
        ]);
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        return $admin;
    }
}
