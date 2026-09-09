<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Wraps WalletService::debit() with old-jewellery-credit bookkeeping.
 * WalletService remains the only writer of users.wallet_balance and
 * wallet_transactions — this class only decides which
 * old_jewellery_wallet_credits rows get decremented (soonest-expires_at
 * first) to reflect that spend, entirely within the same DB transaction
 * as the debit so the two can never drift apart.
 */
class OldJewelleryWalletSpendService
{
    public function __construct(private readonly WalletService $walletService) {}

    public function applySpend(User $user, float $amount, Model $reference): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reference) {
            $transaction = $this->walletService->debit($user, $amount, 'order_payment', $reference);

            $remainingToConsume = $amount;

            $credits = OldJewelleryWalletCredit::where('user_id', $user->id)
                ->whereIn('status', ['active', 'partially_used'])
                ->where('remaining_amount', '>', 0)
                ->orderBy('expires_at')
                ->lockForUpdate()
                ->get();

            foreach ($credits as $credit) {
                if ($remainingToConsume <= 0) {
                    break;
                }

                $consume = min($remainingToConsume, (float) $credit->remaining_amount);
                $newRemaining = round((float) $credit->remaining_amount - $consume, 2);

                $credit->update([
                    'remaining_amount' => $newRemaining,
                    'status' => $newRemaining <= 0 ? 'used' : 'partially_used',
                ]);

                $remainingToConsume = round($remainingToConsume - $consume, 2);
            }

            return $transaction;
        });
    }
}
