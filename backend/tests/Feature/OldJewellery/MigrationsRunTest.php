<?php

namespace Tests\Feature\OldJewellery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_old_jewellery_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('vendors'));
        $this->assertTrue(Schema::hasColumns('vendors', [
            'name', 'company_name', 'mobile', 'mobile_verified_at', 'email', 'whatsapp_number', 'is_active',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_requests'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_requests', [
            'request_number', 'user_id', 'status', 'bidding_start_at', 'bidding_end_at',
            'winning_bid_id', 'final_amount', 'deduction_amount', 'credited_amount', 'closed_at',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_vendor_invitations'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_vendor_invitations', [
            'old_jewellery_request_id', 'vendor_id', 'token_hash', 'expires_at',
            'response_status', 'decline_reason', 'responded_at', 'notified_at',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_bids'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_bids', [
            'old_jewellery_request_id', 'bidder_type', 'vendor_id', 'admin_user_id',
            'invitation_id', 'amount', 'is_valid', 'submitted_at',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_activity_logs'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_activity_logs', [
            'old_jewellery_request_id', 'actor_type', 'actor_id', 'action', 'from_status', 'to_status', 'metadata',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_wallet_credits'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_wallet_credits', [
            'user_id', 'old_jewellery_request_id', 'wallet_transaction_id', 'gross_amount',
            'deduction_amount', 'credited_amount', 'remaining_amount', 'credited_at', 'expires_at', 'status',
        ]));
    }
}
