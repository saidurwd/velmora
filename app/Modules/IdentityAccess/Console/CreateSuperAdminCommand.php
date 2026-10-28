<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Console;

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use App\Modules\IdentityAccess\Rules\StrongPassword;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/** Bootstraps the first administrator. No default credentials exist anywhere: this is the only way to create one. */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'velmora:create-super-admin {email : Work email} {--name= : Display name} {--force : Create another Super Admin even if one exists}';

    protected $description = 'Create a Super Admin (MFA is enrolled at first sign-in)';

    public function handle(AuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => 'required|email:rfc|max:254'])->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with this email already exists.');

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $exists = User::query()->where('account_type', AccountType::Staff->value)->role(PermissionCatalogue::SUPER_ADMIN, 'admin')->exists();
        if ($exists && ! $this->option('force')) {
            $this->error('A Super Admin already exists. Use --force to add another.');

            return self::FAILURE;
        }

        $pw = password('Choose a password (min 10 characters)', required: true);
        $check = Validator::make(['password' => $pw, 'email' => $email], ['password' => [new StrongPassword]]);
        if ($check->fails()) {
            $this->error((string) $check->errors()->first('password'));

            return self::FAILURE;
        }

        $user = new User;
        $user->fill(['name' => (string) ($this->option('name') ?: 'Super Admin'), 'email' => $email, 'password' => $pw]);
        $user->forceFill(['account_type' => AccountType::Staff, 'status' => UserStatus::Active, 'email_verified_at' => now('UTC')])->save();
        $user->assignRole(PermissionCatalogue::SUPER_ADMIN);

        $audit->record('staff.super_admin_created', $user, after: ['email' => $email, 'via' => 'cli'], actor: Actor::system('cli'));

        $this->info("Super Admin {$email} created. They will set up two-step verification at first sign-in: ".url('/admin/login'));

        return self::SUCCESS;
    }
}
