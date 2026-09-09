<?php

namespace App\Jobs;

use App\Models\OldJewelleryWalletCredit;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotent via the query below: only rows still active/partially_used/used
 * past expires_at are touched, and each is flipped to 'expired' in the same
 * pass that debits it — a second run finds zero matching rows. Only the
 * *unused remainder* is debited (already-spent portion, tracked separately
 * on the row by checkout's spend-order logic, is left alone) — a fully
 * 'used' credit has nothing left to debit but is still stamped 'expired' so
 * it doesn't sit forever looking like a still-live credit past its
 * expiry date.
 */
class ExpireOldJewelleryWalletCreditsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(?WalletService $walletService = null): void
    {
        $walletService ??= app(WalletService::class);

        OldJewelleryWalletCredit::whereIn('status', ['active', 'partially_used', 'used'])
            ->where('expires_at', '<=', now())
            ->cursor()
            ->each(function (OldJewelleryWalletCredit $credit) use ($walletService) {
                $remaining = (float) $credit->remaining_amount;

                if ($remaining > 0) {
                    $walletService->debit($credit->user, $remaining, 'old_jewellery_wallet_expired', $credit->request);
                }

                $credit->update(['status' => 'expired', 'remaining_amount' => 0]);
            });
    }
}
