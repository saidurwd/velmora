<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\IdentityAccess\Contracts\CustomerRegistration;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Events\UserRegistered;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\AccountAlreadyExistsNotification;
use App\Modules\IdentityAccess\Rules\StrongPassword;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a customer from email + password. Returns nothing on purpose: the caller shows the same
 * "check your email" message whether the address was new or already registered (no account enumeration).
 * An existing address gets an "account already exists" email instead.
 */
final class RegisterCustomerWithEmail implements CustomerRegistration
{
    public function __construct(private readonly Outbox $outbox, private readonly AuditLogger $audit, private readonly PolicyAcceptances $policies) {}

    /** @param  list<int>  $policyReleaseIds */
    public function handle(string $name, string $email, string $password, string $locale, array $policyReleaseIds = []): void
    {
        $this->registerNew($name, $email, $password, $locale, $policyReleaseIds);
    }

    public function passwordRule(): ValidationRule
    {
        return new StrongPassword;
    }

    public function registerNew(string $name, string $email, string $password, string $locale, ?array $policyReleaseIds = null): ?int
    {
        $email = mb_strtolower(trim($email));

        // before anything else, so a new and an existing address are treated alike
        $policyReleaseIds === null || $this->policies->assertShown(null, 'signup', null, $policyReleaseIds);

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            Hash::make($password); // keep the work comparable to the new-account path
            $existing->notify(new AccountAlreadyExistsNotification);

            return null;
        }

        try {
            $user = Transactions::run(function () use ($name, $email, $password, $locale, $policyReleaseIds): User {
                $user = new User;
                $user->fill(['name' => $name, 'email' => $email, 'password' => $password, 'locale' => $locale]);
                $user->forceFill(['account_type' => AccountType::Customer, 'status' => UserStatus::Active])->save();

                $this->outbox->record(new UserRegistered($user->id, $user->public_id, $name, $email, null));
                $policyReleaseIds === null || $policyReleaseIds === [] || $this->policies->accept($user->id, null, 'signup', $policyReleaseIds);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // lost a race with another registration of the same address: treat as "already exists"
        }

        $this->audit->record('auth.registered', $user, after: ['method' => 'email'], actor: Actor::guest());
        $user->sendEmailVerificationNotification();

        return $user->id;
    }
}
