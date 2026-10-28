<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Models;

use App\Modules\IdentityAccess\Database\Factories\UserFactory;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Notifications\ResetPasswordNotification;
use App\Modules\IdentityAccess\Notifications\VerifyEmailNotification;
use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Single identity table for customers and staff (account_type). Email OR phone identifies a customer;
 * staff always have email + password + TOTP. The `admin` guard name scopes Spatie roles to staff.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password
 * @property AccountType $account_type
 * @property string $locale
 * @property UserStatus $status
 * @property int $failed_login_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $phone_verified_at
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable;

    protected string $guard_name = 'admin';

    /** @var list<string> Explicit allow-list: no mass-assignment of status, account_type, verification or lock fields. */
    protected $fillable = ['name', 'email', 'phone', 'password', 'locale'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'account_type' => AccountType::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function preferredLocale(): string
    {
        return $this->locale;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification((string) $token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function isStaff(): bool
    {
        return $this->account_type === AccountType::Staff;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active
            && ($this->locked_until === null || $this->locked_until->lessThanOrEqualTo(app(Clock::class)->now()));
    }

    /** @return HasOne<StaffMfaCredential, $this> */
    public function mfa(): HasOne
    {
        return $this->hasOne(StaffMfaCredential::class);
    }
}
