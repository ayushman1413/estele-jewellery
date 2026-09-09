<?php

namespace Tests\Feature\OldJewellery;

use App\Jobs\ExpireOldJewelleryWalletCreditsJob;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireWalletCreditsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredit(User $user, float $remaining, string $status, \Carbon\Carbon $expiresAt): OldJewelleryWalletCredit
    {
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-'.random_int(300000, 399999),
            'status' => 'wallet_credited',
        ]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 900,
            'balance_after' => $user->wallet_balance,
            'reason' => 'old_jewellery_sale',
        ]);

        return OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $transaction->id,
            'gross_amount' => 1000,
            'deduction_amount' => 100,
            'credited_amount' => 900,
            'remaining_amount' => $remaining,
            'credited_at' => now()->subDays(10),
            'expires_at' => $expiresAt,
            'status' => $status,
        ]);
    }

    public function test_expires_unused_credit_past_expiry_and_debits_remainder(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $credit = $this->makeCredit($user, 900, 'active', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_partially_used_credit_only_debits_remaining_amount(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $credit = $this->makeCredit($user, 300, 'partially_used', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_does_not_touch_credits_not_yet_expired(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $credit = $this->makeCredit($user, 900, 'active', now()->addDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('active', $credit->fresh()->status);
        $this->assertSame('900.00', $user->fresh()->wallet_balance);
    }

    public function test_fully_used_credit_is_marked_expired_without_debiting(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $credit = $this->makeCredit($user, 0, 'used', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_running_twice_does_not_double_debit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $this->makeCredit($user, 900, 'active', now()->subDay());

        $job = new ExpireOldJewelleryWalletCreditsJob();
        $job->handle();
        $job->handle();

        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_parent_request_transitions_to_wallet_expired(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $credit = $this->makeCredit($user, 900, 'active', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('wallet_expired', $credit->fresh()->request->status);
        $this->assertDatabaseHas('old_jewellery_activity_logs', [
            'old_jewellery_request_id' => $credit->old_jewellery_request_id,
            'action' => 'wallet_expired',
            'from_status' => 'wallet_credited',
            'to_status' => 'wallet_expired',
        ]);
    }

    public function test_parent_request_not_touched_if_already_completed(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $credit = $this->makeCredit($user, 0, 'used', now()->subDay());
        $credit->request->update(['status' => 'completed']);

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('completed', $credit->fresh()->request->status);
    }
}
