<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewelleryWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSelectedRequest(float $finalAmount): OldJewelleryRequest
    {
        $user = User::factory()->create(['wallet_balance' => 0]);

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000030',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);

        $bid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => $finalAmount,
            'submitted_at' => now(),
        ]);

        $request->update([
            'winning_bid_id' => $bid->id,
            'final_amount' => $finalAmount,
            'status' => 'bid_selected',
        ]);

        return $request->fresh();
    }

    public function test_credits_ninety_percent_and_deducts_ten_percent(): void
    {
        $request = $this->makeSelectedRequest(1100.00);

        $credit = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertSame('110.00', $credit->deduction_amount);
        $this->assertSame('990.00', $credit->credited_amount);
        $this->assertSame('1100.00', $credit->gross_amount);
        $this->assertSame('990.00', $request->user->fresh()->wallet_balance);
        $this->assertSame('wallet_credited', $request->fresh()->status);
    }

    public function test_deduction_and_credit_always_sum_to_gross(): void
    {
        // 33.33 is a classic rounding trap for a 10%/90% split.
        $request = $this->makeSelectedRequest(33.33);

        $credit = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertEqualsWithDelta(
            (float) $credit->gross_amount,
            (float) $credit->deduction_amount + (float) $credit->credited_amount,
            0.0001,
        );
    }

    public function test_expiry_is_exactly_ten_days_from_credit(): void
    {
        $request = $this->makeSelectedRequest(1000);

        $credit = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertSame(
            $credit->credited_at->copy()->addDays(10)->toDateString(),
            $credit->expires_at->toDateString(),
        );
    }

    public function test_calling_twice_does_not_double_credit(): void
    {
        $request = $this->makeSelectedRequest(1000);
        $service = app(OldJewelleryWalletService::class);

        $service->creditForRequest($request);
        $service->creditForRequest($request->fresh());

        $this->assertSame('900.00', $request->user->fresh()->wallet_balance);
        $this->assertSame(1, \App\Models\OldJewelleryWalletCredit::where('old_jewellery_request_id', $request->id)->count());
    }

    public function test_returns_null_when_no_final_amount_set(): void
    {
        $user = User::factory()->create();
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000031',
            'status' => 'bidding_closed',
        ]);

        $result = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertNull($result);
    }
}
