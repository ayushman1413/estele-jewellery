<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OldJewellerySellPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_landing_page(): void
    {
        $this->get(route('account.sell-jewellery.landing'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_landing_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.landing'))
            ->assertOk();
    }

    public function test_authenticated_user_can_view_create_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.create'))
            ->assertOk();
    }

    public function test_owner_can_view_their_request(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertOk()
            ->assertSee($request->request_number);
    }

    public function test_a_user_cannot_view_another_users_request(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertForbidden();
    }

    public function test_index_lists_only_the_users_own_requests(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->makeRequest(['user_id' => $user->id]);
        $otherRequest = $this->makeRequest(['user_id' => $other->id]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.index'))
            ->assertOk()
            ->assertSee($mine->request_number)
            ->assertDontSee($otherRequest->request_number);
    }

    public function test_wallet_page_shows_balance_and_credits(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.wallet'))
            ->assertOk()
            ->assertSee('500');
    }

    public function test_show_page_renders_stepper_for_bidding_active_status(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $user->id, 'status' => 'bidding_active']);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('data-poll-status', false);
    }

    public function test_show_page_displays_final_amount_when_completed(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest([
            'user_id' => $user->id,
            'status' => 'completed',
            'final_amount' => 15000,
            'deduction_amount' => 1500,
            'credited_amount' => 13500,
        ]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertOk()
            ->assertSee('15,000')
            ->assertSee('13,500');
    }

    public function test_store_creates_a_request_and_redirects_to_show(): void
    {
        Storage::fake('original_images');
        $user = User::factory()->create();

        // Spatie MediaLibrary's acceptsMimeTypes() validates the *actual*
        // sniffed content type of the uploaded file, not the mimetype param
        // passed to UploadedFile::fake()->create() — a plain fake() file has
        // no real bytes and sniffs as application/x-empty, so it is rejected
        // regardless of the declared mime. Seed minimal MP4 ftyp-box magic
        // bytes so the fake file is genuinely detected as video/mp4.
        $video = UploadedFile::fake()->create('video.mp4', 100, 'video/mp4');
        file_put_contents(
            $video->getRealPath(),
            hex2bin('00000018667479706d703432000000006d703432').str_repeat("\0", 100000)
        );

        $response = $this->actingAs($user)->post(route('account.sell-jewellery.store'), [
            'description' => 'A gold necklace',
            'video' => $video,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('old_jewellery_requests', [
            'user_id' => $user->id,
            'description' => 'A gold necklace',
        ]);
    }

    public function test_store_requires_a_video(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('account.sell-jewellery.store'), [
            'description' => 'Missing video',
        ]);

        $response->assertSessionHasErrors('video');
        $this->assertDatabaseMissing('old_jewellery_requests', ['description' => 'Missing video']);
    }

    public function test_wallet_page_shows_whole_number_days_remaining_not_a_raw_float(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $user->id, 'status' => 'completed']);
        $txn = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 9000,
            'balance_after' => 9000,
            'reason' => 'old_jewellery_sale',
        ]);
        OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $txn->id,
            'gross_amount' => 10000,
            'deduction_amount' => 1000,
            'credited_amount' => 9000,
            'remaining_amount' => 9000,
            'credited_at' => now(),
            'expires_at' => now()->addDays(9)->addHours(17),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get(route('account.sell-jewellery.wallet'));

        $response->assertOk();
        $response->assertSee('9d left');
    }

    public function test_status_endpoint_returns_current_status_for_the_owner(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $user->id, 'status' => 'bidding_active']);

        $response = $this->actingAs($user)
            ->getJson(route('account.sell-jewellery.status', $request));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'bidding_active')
            ->assertJsonPath('data.request_number', $request->request_number);
    }

    public function test_status_endpoint_rejects_another_users_request(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->getJson(route('account.sell-jewellery.status', $request))
            ->assertForbidden();
    }

    private function makeRequest(array $overrides = []): OldJewelleryRequest
    {
        return OldJewelleryRequest::create(array_merge([
            'request_number' => 'OJ-TEST-'.uniqid(),
            'description' => 'A gold chain',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ], $overrides));
    }
}
