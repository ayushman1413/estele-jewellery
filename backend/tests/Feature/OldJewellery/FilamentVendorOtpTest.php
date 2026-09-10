<?php

namespace Tests\Feature\OldJewellery;

use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentVendorOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_otp_action_issues_a_code_for_the_vendor_mobile(): void
    {
        $this->actingAsSuperAdmin();
        $vendor = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true]);

        Livewire::test(EditVendor::class, ['record' => $vendor->id])
            ->callAction('send_otp')
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('otp_codes', ['phone' => '9111111111']);
    }

    public function test_verify_otp_action_marks_mobile_verified_on_correct_code(): void
    {
        $this->actingAsSuperAdmin();
        $vendor = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true]);
        OtpCode::create(['phone' => '9111111111', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);

        Livewire::test(EditVendor::class, ['record' => $vendor->id])
            ->callAction('verify_otp', ['code' => '654321'])
            ->assertHasNoActionErrors();
        $this->assertNull($vendor->fresh()->mobile_verified_at);

        Livewire::test(EditVendor::class, ['record' => $vendor->id])
            ->callAction('verify_otp', ['code' => '123456'])
            ->assertHasNoActionErrors();
        $this->assertNotNull($vendor->fresh()->mobile_verified_at);
    }

    public function test_changing_mobile_resets_verification(): void
    {
        $this->actingAsSuperAdmin();
        $vendor = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true, 'mobile_verified_at' => now()]);

        Livewire::test(EditVendor::class, ['record' => $vendor->id])
            ->fillForm(['mobile' => '9999999999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($vendor->fresh()->mobile_verified_at);
    }

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        return $admin;
    }
}
