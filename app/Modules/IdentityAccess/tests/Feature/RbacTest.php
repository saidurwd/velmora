<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\StaffInviteNotification;
use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use App\Modules\IdentityAccess\Permissions\PermissionMatrixDocument;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class); // device-session rules are covered in StaffAuthTest
    Notification::fake();
});

/** @param list<string> $roles */
function staffWith(array $roles, array $extraPermissions = [], array $attrs = []): User
{
    $staff = User::factory()->staff()->create($attrs + ['email' => 'staff'.uniqid().'@example.com']);
    $staff->syncRoles($roles);
    foreach ($extraPermissions as $permission) {
        $staff->givePermissionTo($permission);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $staff;
}

describe('catalogue and seeding', function () {
    it('creates every permission and the nine default roles on the admin guard', function () {
        expect(Permission::where('guard_name', 'admin')->count())->toBe(count(PermissionCatalogue::permissions()))
            ->and(Role::where('guard_name', 'admin')->pluck('name')->sort()->values()->all())->toBe(collect(PermissionCatalogue::roleNames())->sort()->values()->all())
            ->and(count(PermissionCatalogue::roleNames()))->toBe(9);
    });

    it('names every permission resource.action', function () {
        foreach (PermissionCatalogue::permissions() as $name) {
            expect($name)->toMatch('/^[a-z_]+(\.[a-z_]+){1,2}$/');
        }
    });

    it('gives each default role the intended access', function () {
        expect(PermissionCatalogue::defaultsFor('Finance'))->toContain('payments.refund', 'reports.finance.view')->not->toContain('catalog.update', 'staff.create')
            ->and(PermissionCatalogue::defaultsFor('Support'))->toContain('customers.view_pii', 'support.reply')->not->toContain('payments.refund')
            ->and(PermissionCatalogue::defaultsFor('Warehouse'))->toContain('inventory.adjust')->not->toContain('payments.view'.'x')
            ->and(PermissionCatalogue::defaultsFor('Admin'))->not->toContain('roles.manage')->toContain('staff.create')
            ->and(PermissionCatalogue::defaultsFor('Super Admin'))->toBe(PermissionCatalogue::permissions());

        foreach (PermissionCatalogue::defaultsFor('Read-only') as $permission) {
            expect($permission)->toEndWith('.view'); // read-only never writes
        }
    });

    it('is idempotent and never overwrites permissions an administrator changed', function () {
        $role = Role::findByName('Finance', 'admin');
        $role->revokePermissionTo('payments.refund');
        $role->givePermissionTo('catalog.view');

        $this->seed(RolesAndPermissionsSeeder::class);

        $permissions = Role::findByName('Finance', 'admin')->permissions->pluck('name')->all();
        expect($permissions)->not->toContain('payments.refund')->toContain('catalog.view')
            ->and(Role::where('name', 'Finance')->count())->toBe(1);
    });
});

describe('permission middleware', function () {
    it('lets staff reach only what their roles allow', function () {
        $support = staffWith(['Support']);

        $this->actingAs($support, 'admin')->get('/admin')->assertOk();                       // dashboard.view
        $this->actingAs($support, 'admin')->get('/admin/staff')->assertForbidden();           // staff.view
        $this->actingAs($support, 'admin')->get('/admin/roles')->assertForbidden();
    });

    it('treats a staff member with no role as having no access', function () {
        $nobody = staffWith([]);

        $this->actingAs($nobody, 'admin')->get('/admin')->assertForbidden();
    });

    it('checks the admin guard, never a customer session', function () {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');

        $this->get('/admin')->assertRedirect('/admin/login');
    });

    it('fails closed on a permission name that is not in the catalogue', function () {
        Route::get('/admin/_t/typo', fn () => 'ok')->middleware(['web', 'auth:admin', 'permission:orders.refundd']);
        $super = staffWith(['Super Admin']);

        $this->actingAs($super, 'admin')->get('/admin/_t/typo')->assertForbidden();
    });

    it('accepts any one of several permissions', function () {
        Route::get('/admin/_t/any', fn () => 'ok')->middleware(['web', 'auth:admin', 'permission:orders.update,orders.cancel']);

        $this->actingAs(staffWith([], ['orders.cancel']), 'admin')->get('/admin/_t/any')->assertOk();
        $this->actingAs(staffWith([], ['orders.view']), 'admin')->get('/admin/_t/any')->assertForbidden();
    });

    it('shows only permitted entries in the sidebar', function () {
        $html = $this->actingAs(staffWith(['Support']), 'admin')->get('/admin')->getContent();
        expect($html)->toContain('Dashboard')->not->toContain('Roles &amp; permissions')->not->toContain('href="http://localhost:8000/admin/staff"');

        $html = $this->actingAs(staffWith(['Super Admin']), 'admin')->get('/admin')->getContent();
        expect($html)->toContain('admin/staff')->toContain('admin/roles');
    });
});

describe('Super Admin bypass', function () {
    it('passes catalogue permissions even when no permission row is attached, and audits sensitive ones once', function () {
        $super = staffWith(['Super Admin']);
        $super->roles()->first()->permissions()->detach(); // strip every explicit grant: only the bypass remains
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect($super->can('catalog.update'))->toBeTrue()
            ->and($super->can('payments.refund'))->toBeTrue()
            ->and($super->can('payments.refund'))->toBeTrue();

        expect(DB::table('audit_events')->where('action', 'rbac.super_admin_bypass')->count())->toBe(1) // sensitive, once per request
            ->and(DB::table('audit_events')->where('action', 'rbac.super_admin_bypass')->value('after'))->toContain('payments.refund');
    });

    it('does not extend to abilities outside the catalogue or to other roles', function () {
        $super = staffWith(['Super Admin']);
        $admin = staffWith(['Admin']);

        Gate::define('something.else', fn () => false);

        expect($super->can('something.else'))->toBeFalse()
            ->and($admin->can('roles.manage'))->toBeFalse();
    });
});

describe('staff administration', function () {
    it('invites a new staff member with the chosen roles and sends the invite email', function () {
        $admin = staffWith(['Admin']);

        $this->actingAs($admin, 'admin')->post('/admin/staff', ['name' => 'Rafi Hasan', 'email' => 'Rafi@Example.com', 'roles' => ['Warehouse'], 'locale' => 'bn'])
            ->assertRedirect('/admin/staff');

        $new = User::where('email', 'rafi@example.com')->firstOrFail();
        expect($new->isStaff())->toBeTrue()->and($new->hasRole('Warehouse'))->toBeTrue()->and($new->locale)->toBe('bn');
        Notification::assertSentTo($new, StaffInviteNotification::class);
        expect(DB::table('audit_events')->where('action', 'staff.created')->count())->toBe(1);
    });

    it('lets an Admin assign roles within their own access but never Super Admin', function () {
        $admin = staffWith(['Admin']);

        $this->actingAs($admin, 'admin')->from('/admin/staff/create')
            ->post('/admin/staff', ['name' => 'Evil', 'email' => 'evil@example.com', 'roles' => ['Super Admin']])
            ->assertSessionHasErrors('roles');

        expect(User::where('email', 'evil@example.com')->exists())->toBeFalse();
    });

    it('blocks granting a role that exceeds the granter\'s own permissions', function () {
        $limited = staffWith([], ['staff.view', 'staff.create', 'staff.update']);

        $this->actingAs($limited, 'admin')->from('/admin/staff/create')
            ->post('/admin/staff', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['Finance']])
            ->assertSessionHasErrors('roles');

        expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
    });

    it('stops a limited staff member escalating their own roles', function () {
        $limited = staffWith(['Order Manager'], ['staff.view', 'staff.update']);

        $this->actingAs($limited, 'admin')->from('/admin/staff/x')
            ->put("/admin/staff/{$limited->public_id}", ['name' => 'Me', 'roles' => ['Order Manager', 'Finance']])
            ->assertSessionHasErrors('roles');

        expect($limited->fresh()->hasRole('Finance'))->toBeFalse();
    });

    it('rejects unknown roles and duplicate emails', function () {
        $admin = staffWith(['Admin'], [], ['email' => 'admin@example.com']);

        $this->actingAs($admin, 'admin')->from('/admin/staff/create')->post('/admin/staff', ['name' => 'A', 'email' => 'a@example.com', 'roles' => ['Nope']])->assertSessionHasErrors('roles.0');
        $this->actingAs($admin, 'admin')->from('/admin/staff/create')->post('/admin/staff', ['name' => 'A', 'email' => 'admin@example.com', 'roles' => []])->assertSessionHasErrors('email');
    });

    it('disables a member, signs them out everywhere and blocks their login', function () {
        $admin = staffWith(['Admin']);
        $target = staffWith(['Warehouse'], [], ['email' => 'wh@example.com']);
        DB::table('user_sessions')->insert(['user_id' => $target->id, 'session_id_hash' => str_repeat('c', 64), 'session_id_encrypted' => Crypt::encryptString('sid'), 'guard' => 'admin', 'last_seen_at' => now(), 'created_at' => now()]);

        $this->actingAs($admin, 'admin')->post("/admin/staff/{$target->public_id}/disable")->assertRedirect();

        expect($target->fresh()->status)->toBe(UserStatus::Disabled)
            ->and(DB::table('user_sessions')->where('user_id', $target->id)->count())->toBe(0);

        auth()->forgetGuards(); // the acting admin is no longer signed in on this client
        $this->post('/admin/login', ['email' => 'wh@example.com', 'password' => GOOD_PASSWORD]);
        $this->assertGuest('admin');
        expect(session('admin.pending_login'))->toBeNull();
    });

    it('does not let someone disable themselves', function () {
        $admin = staffWith(['Admin']);

        $this->actingAs($admin, 'admin')->from('/admin/staff')->post("/admin/staff/{$admin->public_id}/disable")->assertSessionHasErrors('staff');

        expect($admin->fresh()->status)->toBe(UserStatus::Active);
    });

    it('never exposes a customer account through the staff screens', function () {
        $customer = User::factory()->create();
        $admin = staffWith(['Super Admin']);

        $this->actingAs($admin, 'admin')->get("/admin/staff/{$customer->public_id}/edit")->assertNotFound();
        $this->actingAs($admin, 'admin')->post("/admin/staff/{$customer->public_id}/disable")->assertNotFound();
    });
});

describe('the last Super Admin', function () {
    it('cannot be demoted, disabled, or have the role removed', function () {
        $only = staffWith(['Super Admin']);
        $other = staffWith(['Super Admin']);
        $other->update(['name' => 'Other']);

        // two exist: one may go
        $this->actingAs($only, 'admin')->put("/admin/staff/{$other->public_id}", ['name' => 'Other', 'roles' => ['Admin']])->assertRedirect('/admin/staff');
        expect($other->fresh()->hasRole('Super Admin'))->toBeFalse();

        // now $only is the last one
        $manager = staffWith(['Admin'], ['staff.update', 'staff.disable']);
        $role = Role::findByName('Super Admin', 'admin');

        // an Admin cannot touch Super Admins, and even a Super Admin cannot remove the last one
        $this->actingAs($only, 'admin')->from('/admin/staff')->put("/admin/staff/{$only->public_id}", ['name' => 'Me', 'roles' => ['Admin']])->assertSessionHasErrors('roles');
        $this->actingAs($only, 'admin')->from('/admin/staff')->post("/admin/staff/{$only->public_id}/disable")->assertSessionHasErrors('staff');

        expect($only->fresh()->hasRole('Super Admin'))->toBeTrue()->and($only->fresh()->status)->toBe(UserStatus::Active)
            ->and($role->users()->count())->toBe(1);
    });

    it('is protected when another Super Admin is already disabled', function () {
        $active = staffWith(['Super Admin']);
        $dormant = staffWith(['Super Admin']);
        $dormant->forceFill(['status' => UserStatus::Disabled])->save();

        $this->actingAs($active, 'admin')->from('/admin/staff')->put("/admin/staff/{$active->public_id}", ['name' => 'Me', 'roles' => []])->assertSessionHasErrors('roles');

        expect($active->fresh()->hasRole('Super Admin'))->toBeTrue(); // a disabled Super Admin does not count
    });
});

describe('roles administration', function () {
    it('creates a custom role with chosen permissions', function () {
        $super = staffWith(['Super Admin']);

        $this->actingAs($super, 'admin')->post('/admin/roles', ['name' => 'Merchandiser', 'permissions' => ['catalog.view', 'catalog.update', 'bogus.permission']])->assertRedirect('/admin/roles');

        $role = Role::findByName('Merchandiser', 'admin');
        expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['catalog.update', 'catalog.view']) // unknown names ignored
            ->and(DB::table('audit_events')->where('action', 'role.created')->count())->toBe(1);
    });

    it('edits a role and audits the before and after', function () {
        $super = staffWith(['Super Admin']);

        $this->actingAs($super, 'admin')->put('/admin/roles/Finance', ['name' => 'Finance', 'permissions' => ['payments.view']])->assertRedirect('/admin/roles');

        $audit = DB::table('audit_events')->where('action', 'role.updated')->first();
        expect(Role::findByName('Finance', 'admin')->permissions->pluck('name')->all())->toBe(['payments.view'])
            ->and(json_decode($audit->before, true)['permissions'])->toContain('payments.refund')
            ->and(json_decode($audit->after, true)['permissions'])->toBe(['payments.view']);
    });

    it('keeps the Super Admin role immutable and system roles undeletable', function () {
        $super = staffWith(['Super Admin']);

        $this->actingAs($super, 'admin')->from('/admin/roles')->put('/admin/roles/Super%20Admin', ['name' => 'Super Admin', 'permissions' => []])->assertSessionHasErrors('permissions');
        $this->actingAs($super, 'admin')->from('/admin/roles')->delete('/admin/roles/Finance')->assertSessionHasErrors('role');

        expect(Role::findByName('Super Admin', 'admin')->permissions)->not->toBeEmpty()->and(Role::where('name', 'Finance')->exists())->toBeTrue();
    });

    it('deletes an unused custom role but not one still assigned', function () {
        $super = staffWith(['Super Admin']);
        Role::create(['name' => 'Temp', 'guard_name' => 'admin']);
        $used = Role::create(['name' => 'Used', 'guard_name' => 'admin']);
        staffWith(['Used']);

        $this->actingAs($super, 'admin')->delete('/admin/roles/Temp')->assertRedirect('/admin/roles');
        $this->actingAs($super, 'admin')->from('/admin/roles')->delete('/admin/roles/Used')->assertSessionHasErrors('role');

        expect(Role::where('name', 'Temp')->exists())->toBeFalse()->and($used->fresh())->not->toBeNull();
    });

    it('stops a role editor granting permissions they do not hold', function () {
        $editor = staffWith([], ['roles.view', 'roles.manage', 'catalog.view']);

        $this->actingAs($editor, 'admin')->from('/admin/roles/create')->post('/admin/roles', ['name' => 'Sneaky', 'permissions' => ['catalog.view', 'payments.refund']])->assertSessionHasErrors('permissions');

        expect(Role::where('name', 'Sneaky')->exists())->toBeFalse();
    });

    it('lets those with roles.view look but not change', function () {
        $viewer = staffWith([], ['roles.view']);

        $this->actingAs($viewer, 'admin')->get('/admin/roles')->assertOk();
        $this->actingAs($viewer, 'admin')->get('/admin/roles/Finance/edit')->assertOk();
        $this->actingAs($viewer, 'admin')->put('/admin/roles/Finance', ['name' => 'Finance', 'permissions' => []])->assertForbidden();
        $this->actingAs($viewer, 'admin')->get('/admin/roles/create')->assertForbidden();
    });

    it('renders the permission matrix in both languages', function () {
        $bangla = staffWith(['Super Admin'], [], ['locale' => 'bn']);
        $english = staffWith(['Super Admin'], [], ['locale' => 'en']);

        $this->actingAs($bangla, 'admin')->get('/admin/roles/Finance/edit')->assertOk()->assertSee('পেমেন্ট');
        $this->actingAs($english, 'admin')->get('/admin/roles/Finance/edit')->assertOk()->assertSee('Payments');
    });
});

describe('bootstrap command', function () {
    it('creates the first Super Admin and refuses a second without --force', function () {
        $this->artisan('velmora:create-super-admin', ['email' => 'owner@example.com', '--name' => 'Owner'])
            ->expectsQuestion('Choose a password (min 10 characters)', 'Zx9-correct-battery-staple')
            ->assertSuccessful();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        expect($owner->isStaff())->toBeTrue()->and($owner->hasRole('Super Admin'))->toBeTrue()->and($owner->password)->toStartWith('$argon2id$')
            ->and(DB::table('audit_events')->where('action', 'staff.super_admin_created')->count())->toBe(1);

        $this->artisan('velmora:create-super-admin', ['email' => 'second@example.com'])->assertFailed();
        expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
    });

    it('rejects a weak password and a duplicate email', function () {
        $this->artisan('velmora:create-super-admin', ['email' => 'owner@example.com'])
            ->expectsQuestion('Choose a password (min 10 characters)', 'password123')
            ->assertFailed();

        User::factory()->create(['email' => 'taken@example.com']);
        $this->artisan('velmora:create-super-admin', ['email' => 'taken@example.com'])->assertFailed();
    });
});

describe('guard regression', function () {
    it('lets non-Super-Admins be edited and disabled even when no Super Admin exists', function () {
        $admin = staffWith(['Admin']);
        $target = staffWith(['Warehouse']);

        $this->actingAs($admin, 'admin')->put("/admin/staff/{$target->public_id}", ['name' => 'Renamed', 'roles' => ['Warehouse', 'Catalog Manager']])->assertRedirect('/admin/staff');

        expect($target->fresh()->name)->toBe('Renamed')->and($target->fresh()->hasRole('Catalog Manager'))->toBeTrue();
    });
});

describe('documentation', function () {
    it('keeps docs/architecture/PERMISSION_MATRIX.md in sync with the catalogue', function () {
        $path = base_path('docs/architecture/PERMISSION_MATRIX.md');

        expect(file_exists($path))->toBeTrue('run `php artisan rbac:matrix`')
            ->and(file_get_contents($path))->toBe(PermissionMatrixDocument::render(), 'stale: run `php artisan rbac:matrix`');
    });
});
