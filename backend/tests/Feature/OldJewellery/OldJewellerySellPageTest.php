<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->makeRequest(['user_id' => $other->id]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.index'))
            ->assertOk()
            ->assertSee($mine->request_number);
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
            ->assertSee('Bidding Active')
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
