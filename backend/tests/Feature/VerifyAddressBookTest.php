<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My Account > Addresses CRUD (Phase 7). The important thing here isn't the
 * happy path — it's that one user can never read/edit/delete another user's
 * saved address just by guessing/incrementing the address id in the URL.
 */
class VerifyAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private function validAddress(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Home',
            'line1' => '221B Baker Street',
            'city' => 'Hyderabad',
            'state' => 'Telangana',
            'postal_code' => '500001',
            'country' => 'India',
        ], $overrides);
    }

    public function test_a_logged_in_user_can_create_an_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('account.addresses.store'), $this->validAddress())
            ->assertRedirect(route('account.addresses'));

        $this->assertDatabaseHas('addresses', ['user_id' => $user->id, 'line1' => '221B Baker Street']);
    }

    public function test_marking_an_address_default_clears_the_default_flag_on_others(): void
    {
        $user = User::factory()->create();
        $first = Address::create(array_merge($this->validAddress(), ['user_id' => $user->id, 'is_default' => true]));

        $this->actingAs($user)->post(route('account.addresses.store'), $this->validAddress(['label' => 'Work', 'is_default' => '1']));

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue(Address::where('label', 'Work')->first()->is_default);
    }

    public function test_a_user_cannot_update_another_users_address(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $address = Address::create(array_merge($this->validAddress(), ['user_id' => $owner->id]));

        $this->actingAs($attacker)
            ->patch(route('account.addresses.update', $address), $this->validAddress(['line1' => 'Hijacked']))
            ->assertNotFound();

        $this->assertSame('221B Baker Street', $address->fresh()->line1);
    }

    public function test_a_user_cannot_delete_another_users_address(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $address = Address::create(array_merge($this->validAddress(), ['user_id' => $owner->id]));

        $this->actingAs($attacker)
            ->delete(route('account.addresses.destroy', $address))
            ->assertNotFound();

        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }

    public function test_a_user_can_delete_their_own_address(): void
    {
        $user = User::factory()->create();
        $address = Address::create(array_merge($this->validAddress(), ['user_id' => $user->id]));

        $this->actingAs($user)
            ->delete(route('account.addresses.destroy', $address))
            ->assertRedirect(route('account.addresses'));

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        // Mobile OTP is the primary login method now (bootstrap/app.php's
        // redirectGuestsTo) — was route('login') (email/password) before
        // Buy It Now/checkout gained the same login gate.
        $this->get(route('account.addresses'))->assertRedirect(route('login'));
    }
}
