<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Database\Factories;

use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => static::$password ??= Hash::make('correct-horse-battery'),
            'locale' => 'en',
            'remember_token' => null,
        ];
    }

    /** Fields guarded from mass assignment are set after creation. */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->account_type ??= AccountType::Customer;
            $user->status ??= UserStatus::Active;
        });
    }

    public function verified(): static
    {
        return $this->afterMaking(fn (User $u) => $u->forceFill(['email_verified_at' => now(), 'phone_verified_at' => $u->phone ? now() : null]));
    }

    public function phoneOnly(string $phone = '+8801712345678'): static
    {
        return $this->state(['email' => null, 'phone' => $phone, 'password' => null]);
    }

    public function staff(): static
    {
        return $this->afterMaking(fn (User $u) => $u->forceFill(['account_type' => AccountType::Staff, 'email_verified_at' => now()]));
    }

    public function status(UserStatus $status): static
    {
        return $this->afterMaking(fn (User $u) => $u->forceFill(['status' => $status]));
    }
}
