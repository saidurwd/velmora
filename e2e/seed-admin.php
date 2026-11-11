<?php

declare(strict_types=1);

// Browser tests only, in the throw-away e2e database: one Super Admin whose two-step code the test can work out, so the admin pages can be opened the way
// a person opens them (password, then code). The secret and password exist only in this throw-away database; no real account uses them.
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\StaffMfaCredential;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;

app(RolesAndPermissionsSeeder::class)->run();

$user = new User;
$user->fill(['name' => 'E2E Admin', 'email' => 'e2e.admin@example.com', 'password' => 'E2e-Admin-Pass-2026!', 'locale' => 'en']);
$user->forceFill(['account_type' => AccountType::Staff, 'status' => UserStatus::Active, 'email_verified_at' => now('UTC')])->save();
$user->assignRole(PermissionCatalogue::SUPER_ADMIN);

StaffMfaCredential::query()->create(['user_id' => $user->id, 'secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'recovery_codes' => [], 'confirmed_at' => now('UTC')]);
