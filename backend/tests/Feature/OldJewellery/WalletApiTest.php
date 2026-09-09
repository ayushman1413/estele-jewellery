<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_summary_returns_balance(): void
    {
        $user = User::factory()->create(['wallet_balance' => 250.50]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet');

        $response->assertOk()->assertJsonPath('data.balance', '250.50');
    }

    public function test_old_jewellery_credits_endpoint_lists_credits(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000110',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);
        $bid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now(),
        ]);
        $request->update(['winning_bid_id' => $bid->id, 'final_amount' => 1000, 'status' => 'bid_selected']);
        app(OldJewelleryWalletService::class)->creditForRequest($request->fresh());

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet/old-jewellery-credits');

        $response->assertOk()->assertJsonPath('data.0.credited_amount', '900.00');
    }
}
