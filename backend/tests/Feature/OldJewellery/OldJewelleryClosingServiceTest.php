<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewelleryClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveRequest(): OldJewelleryRequest
    {
        return OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000040',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);
    }

    public function test_selects_highest_bid_and_credits_wallet(): void
    {
        $request = $this->makeActiveRequest();

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 800,
            'submitted_at' => now()->subMinutes(30),
        ]);
        $winning = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'amount' => 950,
            'submitted_at' => now()->subMinutes(20),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('wallet_credited', $result->status);
        $this->assertSame($winning->id, $result->winning_bid_id);
        $this->assertSame('950.00', $result->final_amount);
        $this->assertSame('855.00', $request->user->fresh()->wallet_balance);
    }

    public function test_ties_broken_by_earliest_submission(): void
    {
        $request = $this->makeActiveRequest();

        $earlier = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 900,
            'submitted_at' => now()->subMinutes(30),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'amount' => 900,
            'submitted_at' => now()->subMinutes(10),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame($earlier->id, $result->winning_bid_id);
    }

    public function test_no_valid_bids_cancels_without_wallet_action(): void
    {
        $request = $this->makeActiveRequest();

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('cancelled', $result->status);
        $this->assertNull($result->winning_bid_id);
        $this->assertSame('0.00', $request->user->fresh()->wallet_balance);
        $this->assertDatabaseHas('old_jewellery_activity_logs', [
            'old_jewellery_request_id' => $request->id,
            'action' => 'no_valid_bids',
        ]);
    }

    public function test_ignores_invalid_bids(): void
    {
        $request = $this->makeActiveRequest();

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 5000,
            'is_valid' => false,
            'submitted_at' => now()->subMinutes(30),
        ]);
        $valid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 500,
            'submitted_at' => now()->subMinutes(20),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame($valid->id, $result->winning_bid_id);
    }

    public function test_closing_twice_does_not_credit_wallet_twice(): void
    {
        $request = $this->makeActiveRequest();

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now()->subMinutes(20),
        ]);

        $service = app(OldJewelleryClosingService::class);
        $service->close($request);
        $service->close($request->fresh());

        $this->assertSame('900.00', $request->user->fresh()->wallet_balance);
    }

    public function test_does_nothing_if_bidding_window_still_open(): void
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000041',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('bidding_active', $result->status);
    }

    public function test_does_nothing_if_status_is_not_bidding_active(): void
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000042',
            'status' => 'submitted',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('submitted', $result->status);
    }
}
