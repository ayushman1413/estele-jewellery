<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class OldJewelleryWalletService
{
    private const DEDUCTION_RATE = 0.10;

    private const WALLET_VALIDITY_DAYS = 10;

    public function __construct(private readonly WalletService $walletService) {}

    /**
     * Idempotency: an existing OldJewelleryWalletCredit row for this request
     * means crediting already happened — return it as-is rather than
     * crediting a second time. This makes the whole operation safe to call
     * twice (e.g. an overlapping/delayed scheduler run) by construction,
     * not by a secondary "already processed" flag alone.
     */
    public function creditForRequest(OldJewelleryRequest $request): ?OldJewelleryWalletCredit
    {
        $existing = OldJewelleryWalletCredit::where('old_jewellery_request_id', $request->id)->first();
        if ($existing) {
            return $existing;
        }

        if ($request->status !== 'bid_selected' || $request->final_amount === null) {
            return null;
        }

        return DB::transaction(function () use ($request) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            // Re-check inside the lock: another process may have credited
            // (or moved the status) between the check above and this point.
            $existing = OldJewelleryWalletCredit::where('old_jewellery_request_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }

            if ($locked->status !== 'bid_selected' || $locked->final_amount === null) {
                return null;
            }

            $gross = (float) $locked->final_amount;
            $deduction = round($gross * self::DEDUCTION_RATE, 2);
            $credited = round($gross - $deduction, 2);

            $locked->update(['status' => 'wallet_pending']);

            $walletTransaction = $this->walletService->credit(
                $locked->user,
                $credited,
                'old_jewellery_sale',
                $locked,
            );

            $now = now();

            $credit = OldJewelleryWalletCredit::create([
                'user_id' => $locked->user_id,
                'old_jewellery_request_id' => $locked->id,
                'wallet_transaction_id' => $walletTransaction->id,
                'gross_amount' => $gross,
                'deduction_amount' => $deduction,
                'credited_amount' => $credited,
                'remaining_amount' => $credited,
                'credited_at' => $now,
                'expires_at' => $now->copy()->addDays(self::WALLET_VALIDITY_DAYS),
                'status' => 'active',
            ]);

            $locked->update([
                'status' => 'wallet_credited',
                'deduction_amount' => $deduction,
                'credited_amount' => $credited,
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'wallet_credited',
                'from_status' => 'wallet_pending',
                'to_status' => 'wallet_credited',
                'metadata' => [
                    'gross_amount' => $gross,
                    'deduction_amount' => $deduction,
                    'credited_amount' => $credited,
                    'expires_at' => $credit->expires_at->toIso8601String(),
                ],
            ]);

            return $credit;
        });
    }
}
