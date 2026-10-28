<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Database\Seeders;

use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent and deploy-safe: creates missing permissions and roles, and gives a role its default permissions only
 * when the role is created. It never rewrites permissions an administrator already edited.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalogue::permissions() as $name) {
            Permission::findOrCreate($name, 'admin');
        }

        foreach (PermissionCatalogue::roleNames() as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'admin')->first();

            if ($role === null) {
                $role = Role::create(['name' => $roleName, 'guard_name' => 'admin']);
                $role->syncPermissions(PermissionCatalogue::defaultsFor($roleName));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
