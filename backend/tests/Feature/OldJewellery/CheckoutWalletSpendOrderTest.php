<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OldJewellery\OldJewelleryWalletSpendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutWalletSpendOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredit(User $user, float $remaining, \Carbon\Carbon $expiresAt): OldJewelleryWalletCredit
    {
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-'.random_int(500000, 599999),
            'status' => 'wallet_credited',
        ]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => $remaining,
            'balance_after' => $user->wallet_balance,
            'reason' => 'old_jewellery_sale',
        ]);

        return OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $transaction->id,
            'gross_amount' => $remaining,
            'deduction_amount' => 0,
            'credited_amount' => $remaining,
            'remaining_amount' => $remaining,
            'credited_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
    }

    public function test_spends_soonest_expiring_credit_first(): void
    {
        $user = User::factory()->create(['wallet_balance' => 700]);
        $soon = $this->makeCredit($user, 200, now()->addDays(2));
        $later = $this->makeCredit($user, 500, now()->addDays(9));

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-1',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 300, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 300,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        app(OldJewelleryWalletSpendService::class)->applySpend($user, 300, $order);

        $this->assertSame('0.00', $soon->fresh()->remaining_amount);
        $this->assertSame('used', $soon->fresh()->status);
        $this->assertSame('400.00', $later->fresh()->remaining_amount);
        $this->assertSame('partially_used', $later->fresh()->status);
        $this->assertSame('400.00', $user->fresh()->wallet_balance);
    }

    public function test_falls_through_to_non_expiring_balance_after_expiring_credits_exhausted(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        // Only 200 of expiring credit exists; the other 300 in wallet_balance
        // is non-expiring (e.g. reward-submission credit).
        $this->makeCredit($user, 200, now()->addDays(2));

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-2',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 400, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 400,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        $credit = OldJewelleryWalletCredit::where('user_id', $user->id)->first();

        app(OldJewelleryWalletSpendService::class)->applySpend($user, 400, $order);

        $this->assertSame('100.00', $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id, 'type' => 'debit', 'amount' => '400.00',
        ]);
        $this->assertSame('0.00', $credit->fresh()->remaining_amount);
        $this->assertSame('used', $credit->fresh()->status);
    }

    public function test_credit_expired_but_not_yet_swept_is_not_spendable(): void
    {
        // Simulates the window between a credit's expires_at passing and the
        // daily ExpireOldJewelleryWalletCreditsJob sweep actually running:
        // status is still 'active' even though expires_at is already past.
        $user = User::factory()->create(['wallet_balance' => 200]);
        $unswept = $this->makeCredit($user, 200, now()->subHour());

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-4',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 100,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        // wallet_balance (200) is still sufficient to cover the 100 debit
        // even excluding the expired credit, so the debit itself succeeds —
        // the point of this test is that the un-swept expired credit row is
        // never consumed to satisfy it (it should be left untouched for the
        // daily sweep job to expire).
        app(OldJewelleryWalletSpendService::class)->applySpend($user, 100, $order);

        $this->assertSame('100.00', $user->fresh()->wallet_balance);
        $this->assertSame('200.00', $unswept->fresh()->remaining_amount);
        $this->assertSame('active', $unswept->fresh()->status);
    }

    public function test_expired_credits_are_never_spent(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200]);
        $expired = $this->makeCredit($user, 200, now()->subDay());
        $expired->update(['status' => 'expired']);

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-3',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 100,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        // wallet_balance itself must have already excluded the expired
        // portion (Task 13's expiry job debits it) — this test only asserts
        // the spend service doesn't touch the expired credit row itself.
        app(OldJewelleryWalletSpendService::class)->applySpend($user, 100, $order);

        $this->assertSame('200.00', $expired->fresh()->remaining_amount);
        $this->assertSame('expired', $expired->fresh()->status);
    }

    public function test_request_transitions_to_completed_when_credit_fully_spent(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200]);
        $credit = $this->makeCredit($user, 200, now()->addDays(2));

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-5',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 200, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 200,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        app(OldJewelleryWalletSpendService::class)->applySpend($user, 200, $order);

        $this->assertSame('used', $credit->fresh()->status);
        $this->assertSame('completed', $credit->fresh()->request->status);
        $this->assertDatabaseHas('old_jewellery_activity_logs', [
            'old_jewellery_request_id' => $credit->old_jewellery_request_id,
            'action' => 'wallet_spent_completed',
            'to_status' => 'completed',
        ]);
    }

    public function test_request_stays_wallet_credited_on_partial_spend(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200]);
        $credit = $this->makeCredit($user, 200, now()->addDays(2));

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-6',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 50, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 50,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        app(OldJewelleryWalletSpendService::class)->applySpend($user, 50, $order);

        $this->assertSame('partially_used', $credit->fresh()->status);
        $this->assertSame('wallet_credited', $credit->fresh()->request->status);
    }
}
