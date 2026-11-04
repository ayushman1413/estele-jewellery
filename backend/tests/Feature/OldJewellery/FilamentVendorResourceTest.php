<?php

namespace Tests\Feature\OldJewellery;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentVendorResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_vendors_index(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:Vendor', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:Vendor');
        $admin->assignRole($role);

        $response = $this->actingAs($admin)->get('/admin/vendors');

        $response->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'marketing', 'guard_name' => 'web']);
        $user->assignRole('marketing'); // panel-access role with no permissions synced — super_admin bypasses the gate

        $response = $this->actingAs($user)->get('/admin/vendors');

        $response->assertForbidden();
    }
}
