<?php

namespace Tests\Feature\OldJewellery;

use App\Jobs\CloseExpiredOldJewelleryBiddingJob;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseExpiredBiddingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_closes_only_expired_bidding_active_requests(): void
    {
        $expired = OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000200',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $expired->id,
            'bidder_type' => 'vendor',
            'amount' => 500,
            'submitted_at' => now()->subMinutes(30),
        ]);

        $stillOpen = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000201',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        (new CloseExpiredOldJewelleryBiddingJob())->handle();

        $this->assertSame('wallet_credited', $expired->fresh()->status);
        $this->assertSame('bidding_active', $stillOpen->fresh()->status);
    }

    public function test_running_twice_does_not_double_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000202',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now()->subMinutes(30),
        ]);

        $job = new CloseExpiredOldJewelleryBiddingJob();
        $job->handle();
        $job->handle();

        $this->assertSame('900.00', $user->fresh()->wallet_balance);
    }
}
