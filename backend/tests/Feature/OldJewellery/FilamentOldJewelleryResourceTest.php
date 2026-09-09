<?php

namespace Tests\Feature\OldJewellery;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentOldJewelleryResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_old_jewellery_requests_index(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryRequest', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:OldJewelleryRequest');
        $admin->assignRole($role);

        $response = $this->actingAs($admin)->get('/admin/old-jewellery-requests');

        $response->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin'); // has the panel-access role but zero permissions synced

        $response = $this->actingAs($user)->get('/admin/old-jewellery-requests');

        $response->assertForbidden();
    }
}
