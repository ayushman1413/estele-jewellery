<?php

namespace Tests\Feature\OldJewellery;

use App\Http\Resources\OldJewelleryVendorViewResource;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiResourceVendorIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_resource_never_exposes_other_bids_admin_bid_or_token_hash(): void
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000060',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $vendorA = Vendor::create(['name' => 'Vendor A', 'mobile' => '9000000060', 'is_active' => true]);
        $vendorB = Vendor::create(['name' => 'Vendor B', 'mobile' => '9000000061', 'is_active' => true]);

        $invitationA = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendorA->id,
            'token_hash' => hash('sha256', 'token-a'),
            'expires_at' => $request->bidding_end_at,
        ]);

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $vendorB->id,
            'amount' => 5000,
            'submitted_at' => now(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'admin_user_id' => User::factory()->create()->id,
            'amount' => 9999,
            'submitted_at' => now(),
        ]);

        $payload = (new OldJewelleryVendorViewResource($invitationA->load('request')))
            ->response()
            ->getData(true);

        $json = json_encode($payload);

        $this->assertStringNotContainsString('token_hash', $json);
        $this->assertStringNotContainsString('Vendor B', $json);
        $this->assertStringNotContainsString('5000', $json);
        $this->assertStringNotContainsString('9999', $json);
    }
}
