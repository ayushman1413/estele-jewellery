<?php

namespace Tests\Feature\OldJewellery;

use App\Filament\Resources\OldJewelleryRequests\Pages\ViewOldJewelleryRequest;
use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentOldJewelleryViewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_page_shows_bidding_wallet_and_activity_details(): void
    {
        $this->actingAsSuperAdmin();
        [$request, $vendorA, $vendorB] = $this->makeRequestWithBids();

        $response = $this->get("/admin/old-jewellery-requests/{$request->request_number}");

        $response->assertOk()
            ->assertSee('Acme Gold')
            ->assertSee('Bharat Jewels')
            ->assertSee('₹1,100.00')   // highest / final bid
            ->assertSee('₹950.00')     // admin bid
            ->assertSee('₹110.00')     // 10% deduction
            ->assertSee('₹990.00')     // wallet credit
            ->assertSee('Too far away') // decline reason
            ->assertSee('Highest bid selected')
            ->assertSee('Admin submitted valuation')
            ->assertSee('Winner');
    }

    public function test_list_shows_highest_bid_responses_and_wallet_columns(): void
    {
        $this->actingAsSuperAdmin();
        [$request] = $this->makeRequestWithBids();

        $this->get('/admin/old-jewellery-requests')
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Responses')
            ->assertSee('Highest bid')
            ->assertSee('Wallet status')
            ->assertSee('₹1,100.00')
            ->assertSee('₹990.00');
    }

    public function test_admin_can_submit_valuation_from_view_page(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-TEST-VIEW-2',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        Livewire::test(ViewOldJewelleryRequest::class, ['record' => $request->request_number])
            ->callAction('admin_bid', ['amount' => 1234])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('old_jewellery_bids', [
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'admin_user_id' => $admin->id,
            'amount' => 1234,
        ]);
    }

    public function test_admin_can_force_close_bidding_from_view_page(): void
    {
        $this->actingAsSuperAdmin();
        [$request] = $this->makeRequestWithBids(status: 'bidding_active');

        Livewire::test(ViewOldJewelleryRequest::class, ['record' => $request->request_number])
            ->callAction('close_bidding')
            ->assertHasNoActionErrors();

        $this->assertSame('wallet_credited', $request->fresh()->status);
    }

    public function test_admin_video_route_streams_for_permitted_user_and_forbids_others(): void
    {
        Storage::fake('original_images');
        Storage::fake('public');

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-TEST-VIDEO-1',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $video = UploadedFile::fake()->create('video.mp4', 100, 'video/mp4');
        file_put_contents($video->getRealPath(), hex2bin('00000018667479706d703432000000006d703432').str_repeat("\0", 4096));
        $request->addMedia($video)->toMediaCollection('video');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.old-jewellery.video', $request))
            ->assertForbidden();

        $this->actingAsSuperAdmin();
        $response = $this->get(route('admin.old-jewellery.video', $request));
        $response->assertOk();
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));

        $downloadResponse = $this->get(route('admin.old-jewellery.video', ['oldJewelleryRequest' => $request, 'download' => 1]));
        $downloadResponse->assertOk();
        $this->assertStringStartsWith('attachment;', $downloadResponse->headers->get('Content-Disposition'));

        $this->get("/admin/old-jewellery-requests/{$request->request_number}")
            ->assertOk()
            ->assertSee(route('admin.old-jewellery.video', $request), escape: false)
            ->assertSee('Download video');
    }

    public function test_view_page_warns_when_video_is_quicktime_format(): void
    {
        Storage::fake('original_images');
        Storage::fake('public');

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-TEST-VIDEO-2',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $video = UploadedFile::fake()->create('video.mov', 100, 'video/quicktime');
        file_put_contents($video->getRealPath(), hex2bin('0000001c6674797071742020').str_repeat("\0", 4096));
        $request->addMedia($video)->toMediaCollection('video');

        $this->actingAsSuperAdmin();

        $this->get("/admin/old-jewellery-requests/{$request->request_number}")
            ->assertOk()
            ->assertSee('Download video')
            ->assertSee('may not play above in Chrome/Firefox');
    }

    /**
     * @return array{0: OldJewelleryRequest, 1: Vendor, 2: Vendor}
     */
    private function makeRequestWithBids(string $status = 'wallet_credited'): array
    {
        $customer = User::factory()->create(['wallet_balance' => 990]);
        $admin = User::where('email', 'superadmin@test.local')->first() ?? User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $customer->id,
            'request_number' => 'OJ-TEST-VIEW-1',
            'description' => 'Antique kundan necklace',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->addMinutes(5),
        ]);

        $vendorA = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true]);
        $vendorB = Vendor::create(['name' => 'Bharat Jewels', 'mobile' => '9222222222', 'is_active' => true]);
        $vendorC = Vendor::create(['name' => 'Chandni Ornaments', 'mobile' => '9333333333', 'is_active' => true]);

        $inviteA = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id, 'vendor_id' => $vendorA->id,
            'token_hash' => hash('sha256', 'a'), 'expires_at' => $request->bidding_end_at,
            'response_status' => 'accepted', 'responded_at' => now(), 'notified_at' => now(),
        ]);
        $inviteB = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id, 'vendor_id' => $vendorB->id,
            'token_hash' => hash('sha256', 'b'), 'expires_at' => $request->bidding_end_at,
            'response_status' => 'accepted', 'responded_at' => now(), 'notified_at' => now(),
        ]);
        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id, 'vendor_id' => $vendorC->id,
            'token_hash' => hash('sha256', 'c'), 'expires_at' => $request->bidding_end_at,
            'response_status' => 'declined', 'decline_reason' => 'Too far away', 'responded_at' => now(), 'notified_at' => now(),
        ]);

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id, 'bidder_type' => 'vendor', 'vendor_id' => $vendorA->id,
            'invitation_id' => $inviteA->id, 'amount' => 800, 'submitted_at' => now(),
        ]);
        $winning = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id, 'bidder_type' => 'vendor', 'vendor_id' => $vendorB->id,
            'invitation_id' => $inviteB->id, 'amount' => 1100, 'submitted_at' => now(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id, 'bidder_type' => 'admin', 'admin_user_id' => $admin->id,
            'amount' => 950, 'submitted_at' => now(),
        ]);

        OldJewelleryActivityLog::create(['old_jewellery_request_id' => $request->id, 'actor_type' => 'admin', 'actor_id' => $admin->id, 'action' => 'admin_bid_submitted', 'metadata' => ['amount' => 950]]);

        if ($status === 'bidding_active') {
            return [$request, $vendorA, $vendorB];
        }

        $request->update(['status' => 'bidding_closed']);
        $request->update(['status' => 'bid_selected', 'winning_bid_id' => $winning->id, 'final_amount' => 1100, 'deduction_amount' => 110, 'credited_amount' => 990, 'closed_at' => now()]);
        $request->update(['status' => 'wallet_pending']);
        $request->update(['status' => 'wallet_credited']);

        $transaction = WalletTransaction::create([
            'user_id' => $customer->id, 'type' => 'credit', 'amount' => 990, 'balance_after' => 990, 'reason' => 'old_jewellery_sale',
        ]);
        OldJewelleryWalletCredit::create([
            'user_id' => $customer->id, 'old_jewellery_request_id' => $request->id, 'wallet_transaction_id' => $transaction->id,
            'gross_amount' => 1100, 'deduction_amount' => 110, 'credited_amount' => 990, 'remaining_amount' => 990,
            'credited_at' => now(), 'expires_at' => now()->addDays(10), 'status' => 'active',
        ]);
        OldJewelleryActivityLog::create(['old_jewellery_request_id' => $request->id, 'actor_type' => 'system', 'action' => 'bid_selected', 'from_status' => 'bidding_closed', 'to_status' => 'bid_selected', 'metadata' => ['amount' => '1100.00', 'bidder_type' => 'vendor']]);

        return [$request, $vendorA, $vendorB];
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::where('email', 'superadmin@test.local')->first()
            ?? User::factory()->create(['email' => 'superadmin@test.local']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        return $admin;
    }
}
