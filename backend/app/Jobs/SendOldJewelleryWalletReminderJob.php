<?php

namespace App\Jobs;

use App\Models\OldJewelleryWalletCredit;
use App\Notifications\OldJewelleryWalletExpiryReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Three reminder tiers (3 days before, 1 day before, on the expiry date
 * itself), each gated by its own *_sent_at column so a daily run — even if
 * delayed and catching up on more than one day at once — never re-sends a
 * tier already stamped. Tiers are date-based (not "exactly N*24h before"),
 * so a delayed cron that runs once for two calendar days still only sends
 * each tier once.
 */
class SendOldJewelleryWalletReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->sendTier('reminder_3d_sent_at', now()->addDays(3)->toDateString());
        $this->sendTier('reminder_1d_sent_at', now()->addDay()->toDateString());
        $this->sendTier('reminder_0d_sent_at', now()->toDateString());
    }

    private function sendTier(string $column, string $targetDate): void
    {
        OldJewelleryWalletCredit::whereIn('status', ['active', 'partially_used'])
            ->whereNull($column)
            ->whereDate('expires_at', $targetDate)
            ->with('user', 'request')
            ->cursor()
            ->each(function (OldJewelleryWalletCredit $credit) use ($column) {
                Notification::send($credit->user, new OldJewelleryWalletExpiryReminder($credit));
                $credit->update([$column => now()]);
            });
    }
}
