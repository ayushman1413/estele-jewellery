<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VendorTokenAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Illuminate\Foundation\Testing\TestCase (full-app testing) has no
        // defineRoutes() hook — that's an Orchestra Testbench convention for
        // package tests. Registering the throwaway probe route here instead
        // achieves the same thing: a route wrapped only in the middleware
        // under test, with no other app route interfering.
        Route::middleware('vendor.token')->get('/__test/vendor-token/{token}', function () {
            $invitation = request()->attributes->get('old_jewellery_invitation');

            return response()->json(['invitation_id' => $invitation->id]);
        });
    }

    private function makeInvitation(bool $expired = false): array
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000050',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => $expired ? now()->subMinute() : now()->addHours(3),
        ]);

        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000050', 'is_active' => true]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'good-token'),
            'expires_at' => $request->bidding_end_at,
            'response_status' => 'pending',
        ]);

        return [$request, $vendor, $invitation];
    }

    public function test_valid_token_resolves_invitation(): void
    {
        [, , $invitation] = $this->makeInvitation();

        $response = $this->getJson('/__test/vendor-token/good-token');

        $response->assertOk()->assertJson(['invitation_id' => $invitation->id]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->makeInvitation();

        $response = $this->getJson('/__test/vendor-token/wrong-token');

        $response->assertStatus(404);
        $response->assertJson(['success' => false]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->makeInvitation(expired: true);

        $response = $this->getJson('/__test/vendor-token/good-token');

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }
}
