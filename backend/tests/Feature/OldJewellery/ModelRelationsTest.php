<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_resolve_end_to_end(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000001',
            'status' => 'pending',
            'description' => 'A necklace',
        ]);

        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'mobile' => '9000000001',
            'is_active' => true,
        ]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'plaintext-token'),
            'expires_at' => now()->addHours(3),
        ]);

        $bid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $vendor->id,
            'invitation_id' => $invitation->id,
            'amount' => 1100,
            'submitted_at' => now(),
        ]);

        OldJewelleryActivityLog::create([
            'old_jewellery_request_id' => $request->id,
            'actor_type' => 'system',
            'action' => 'request_submitted',
        ]);

        $walletTransaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 990,
            'balance_after' => 990,
            'reason' => 'old_jewellery_sale',
        ]);

        $credit = OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $walletTransaction->id,
            'gross_amount' => 1100,
            'deduction_amount' => 110,
            'credited_amount' => 990,
            'remaining_amount' => 990,
            'credited_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($request->invitations->contains($invitation));
        $this->assertTrue($request->bids->contains($bid));
        $this->assertTrue($request->activityLogs->first()->action === 'request_submitted');
        $this->assertTrue($request->walletCredit->is($credit));
        $this->assertTrue($vendor->invitations->contains($invitation));
        $this->assertTrue($vendor->bids->contains($bid));
        $this->assertTrue($invitation->vendor->is($vendor));
        $this->assertTrue($invitation->bid->is($bid));
        $this->assertTrue($bid->vendor->is($vendor));
        $this->assertTrue($credit->walletTransaction->is($walletTransaction));
    }

    public function test_status_transition_guard_blocks_illegal_moves(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000002',
            'status' => 'pending',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $request->update(['status' => 'wallet_credited']);
    }

    public function test_status_transition_guard_allows_legal_moves(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000003',
            'status' => 'pending',
        ]);

        $request->update(['status' => 'submitted']);

        $this->assertSame('submitted', $request->fresh()->status);
    }
}
