<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A vendor that logs in needs to at least read the requests it was invited to,
 * or the panel is empty for it. Read-only on purpose: which rows it sees is
 * narrowed to its own invitations by OldJewelleryRequestResource, and nothing
 * here lets a vendor change a customer's request.
 *
 * These are defaults, not a lock — an admin can add or remove them in Shield.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'ViewAny:OldJewelleryRequest',
        'View:OldJewelleryRequest',
    ];

    public function up(): void
    {
        $role = Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);

        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        $role = Role::where(['name' => 'vendor', 'guard_name' => 'web'])->first();

        $role?->revokePermissionTo(
            Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->get()
        );
    }
};
