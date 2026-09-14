<?php

namespace Tests\Feature\OldJewellery;

use App\Filament\Resources\Vendors\Pages\CreateVendor;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Vendors\PanelAccessService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A vendor invite must never be able to seize an existing login (a customer,
 * staff member, or another super_admin) by reusing its email — the setup
 * link that follows would hand that account's password to whoever the
 * invite's recipient is, not the account's real owner.
 */
class VendorPanelAccessTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_creating_a_vendor_with_another_users_email_is_rejected_by_the_form(): void
    {
        $this->actingAsSuperAdmin();
        $victim = User::factory()->create(['email' => 'victim@example.com', 'password' => bcrypt('secret')]);
        $victim->assignRole('super_admin');

        Livewire::test(CreateVendor::class)
            ->fillForm([
                'name' => 'New Vendor',
                'mobile' => '9000000001',
                'email' => 'victim@example.com',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertDatabaseMissing('vendors', ['mobile' => '9000000001']);

        $victim->refresh();
        $this->assertTrue($victim->hasRole('super_admin'));
        $this->assertFalse($victim->hasRole('vendor'));
    }

    public function test_service_level_guard_refuses_to_adopt_an_unowned_user_even_if_the_form_is_bypassed(): void
    {
        $this->actingAsSuperAdmin();
        $originalHash = bcrypt('secret');
        $victim = User::factory()->create(['email' => 'victim2@example.com', 'password' => $originalHash]);

        $vendor = Vendor::create([
            'name' => 'Bypass Vendor',
            'mobile' => '9000000002',
            'email' => 'victim2@example.com',
            'is_active' => true,
            'access_role' => Vendor::ACCESS_ROLE_VENDOR,
        ]);

        try {
            app(PanelAccessService::class)->grant($vendor);
            $this->fail('Expected a RuntimeException when the email belongs to another account.');
        } catch (\RuntimeException) {
            // expected
        }

        $victim->refresh();
        $this->assertSame($originalHash, $victim->password, 'the existing account\'s password must be untouched');
        $this->assertFalse($victim->hasRole('vendor'));
        $this->assertNull($vendor->fresh()->user_id, 'the vendor must not end up linked to the other account');
    }

    public function test_a_vendor_can_still_reuse_its_own_previously_linked_user(): void
    {
        $this->actingAsSuperAdmin();
        $vendor = Vendor::create([
            'name' => 'Existing Vendor',
            'mobile' => '9000000003',
            'email' => 'owned@example.com',
            'is_active' => true,
            'access_role' => Vendor::ACCESS_ROLE_VENDOR,
        ]);

        app(PanelAccessService::class)->grantWithoutNotifying($vendor);
        $vendor->refresh();
        $this->assertNotNull($vendor->user_id);
        $firstUserId = $vendor->user_id;

        // Re-granting (e.g. a role change) must reuse the same user, not throw.
        $result = app(PanelAccessService::class)->grantWithoutNotifying($vendor);

        $this->assertTrue($result);
        $this->assertSame($firstUserId, $vendor->fresh()->user_id);
    }
}
