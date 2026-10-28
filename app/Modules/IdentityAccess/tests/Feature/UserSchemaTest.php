<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

it('creates users with a ULID public id and safe defaults', function () {
    $user = User::factory()->create();

    expect($user->public_id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($user->account_type)->toBe(AccountType::Customer)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->locale)->toBe('en');
});

it('requires at least one identifier (email or phone)', function () {
    DB::table('users')->insert(['public_id' => str_repeat('a', 26), 'account_type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
})->throws(QueryException::class);

it('allows phone-only customers without a password', function () {
    $user = User::factory()->phoneOnly('+8801712345678')->create();

    expect($user->password)->toBeNull()->and($user->email)->toBeNull()->and($user->phone)->toBe('+8801712345678');
});

it('requires staff to have email and password', function () {
    expect(fn () => User::factory()->staff()->phoneOnly()->create())->toThrow(QueryException::class);
});

it('enforces unique email (case-insensitive) and unique phone', function () {
    User::factory()->create(['email' => 'Amina@Example.com']);

    expect(fn () => User::factory()->create(['email' => 'amina@example.com']))->toThrow(QueryException::class);

    User::factory()->phoneOnly('+8801811111111')->create();
    expect(fn () => User::factory()->phoneOnly('+8801811111111')->create())->toThrow(QueryException::class);
});

it('rejects invalid account types and statuses at the database level', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['account_type' => 'god']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('users')->where('id', $user->id)->update(['status' => 'weird']))->toThrow(QueryException::class);
});

it('hashes passwords with argon2id', function () {
    $user = User::factory()->create(['password' => 'a-long-test-password']);

    expect($user->password)->toStartWith('$argon2id$')
        ->and(Hash::check('a-long-test-password', $user->password))->toBeTrue()
        ->and(Hash::check('wrong', $user->password))->toBeFalse();
});

it('cannot mass-assign privileged fields', function () {
    $user = new User;
    $user->fill(['name' => 'X', 'account_type' => 'staff', 'status' => 'disabled', 'email_verified_at' => now(), 'failed_login_attempts' => 9, 'public_id' => 'forged']);

    expect($user->getAttributes())->toHaveKey('name')
        ->not->toHaveKeys(['account_type', 'status', 'email_verified_at', 'failed_login_attempts', 'public_id']);
});

it('hides secrets when serialised', function () {
    $array = User::factory()->create()->toArray();

    expect($array)->not->toHaveKeys(['password', 'remember_token']);
});

it('keeps customer and staff guards separate: a staff user is invisible to the customer provider and vice versa', function () {
    $customer = User::factory()->create();
    $staff = User::factory()->staff()->create();

    expect(Auth::guard('web')->getProvider()->retrieveById($customer->id)?->is($customer))->toBeTrue()
        ->and(Auth::guard('web')->getProvider()->retrieveById($staff->id))->toBeNull()
        ->and(Auth::guard('admin')->getProvider()->retrieveById($staff->id)?->is($staff))->toBeTrue()
        ->and(Auth::guard('admin')->getProvider()->retrieveById($customer->id))->toBeNull();
});

it('cannot log a customer into the admin guard', function () {
    $customer = User::factory()->create();

    expect(Auth::guard('admin')->attempt(['email' => $customer->email, 'password' => 'correct-horse-battery']))->toBeFalse();
});

it('keeps Spatie roles scoped to the admin guard', function () {
    $staff = User::factory()->staff()->create();
    $role = Role::create(['name' => 'Catalog Manager', 'guard_name' => 'admin']);

    $staff->assignRole($role);

    expect($staff->hasRole('Catalog Manager'))->toBeTrue();
});
