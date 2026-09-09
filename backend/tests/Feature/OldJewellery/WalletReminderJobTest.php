<?php

namespace Tests\Feature\OldJewellery;

use App\Jobs\SendOldJewelleryWalletReminderJob;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\OldJewelleryWalletExpiryReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WalletReminderJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredit(User $user, \Carbon\Carbon $expiresAt): OldJewelleryWalletCredit
    {
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-'.random_int(400000, 499999),
            'status' => 'wallet_credited',
        ]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 900,
            'balance_after' => 900,
            'reason' => 'old_jewellery_sale',
        ]);

        return OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $transaction->id,
            'gross_amount' => 1000,
            'deduction_amount' => 100,
            'credited_amount' => 900,
            'remaining_amount' => 900,
            'credited_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
    }

    public function test_sends_three_day_reminder_and_stamps_it(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $credit = $this->makeCredit($user, now()->addDays(3));

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertSentTo($user, OldJewelleryWalletExpiryReminder::class);
        $this->assertNotNull($credit->fresh()->reminder_3d_sent_at);
    }

    public function test_does_not_send_the_same_tier_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $credit = $this->makeCredit($user, now()->addDays(3));
        $credit->update(['reminder_3d_sent_at' => now()]);

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertNotSentTo($user, OldJewelleryWalletExpiryReminder::class);
    }

    public function test_sends_one_day_and_expiry_day_reminders(): void
    {
        Notification::fake();

        $userOneDay = User::factory()->create();
        $this->makeCredit($userOneDay, now()->addDay());

        $userToday = User::factory()->create();
        $this->makeCredit($userToday, now());

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertSentTo($userOneDay, OldJewelleryWalletExpiryReminder::class);
        Notification::assertSentTo($userToday, OldJewelleryWalletExpiryReminder::class);
    }

    public function test_ignores_already_expired_credits(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $credit = $this->makeCredit($user, now()->subDay());
        $credit->update(['status' => 'expired']);

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertNotSentTo($user, OldJewelleryWalletExpiryReminder::class);
    }
}
