<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use Illuminate\Contracts\Validation\ValidationRule;

/** Creating a customer account on behalf of another module (for example from the order confirmation page). */
interface CustomerRegistration
{
    /** The rule a new password must pass (the same one the sign-up page uses). */
    public function passwordRule(): ValidationRule;

    /**
     * Creates the account and sends the verification email. Returns the new account's id, or null when the address already has an account
     * (that account is told by email; the caller must show the same message either way so addresses cannot be probed).
     *
     * $policyReleaseIds are the policies the person was shown and accepted; they are checked against what is outstanding and recorded with the account.
     * Pass null only when the person has already accepted elsewhere (a guest making an account from their order, whose acceptances are carried over).
     *
     * @param  list<int>|null  $policyReleaseIds
     *
     * @throws \DomainException when a policy changed while the person was reading it (the message is a cms::policies.errors key)
     */
    public function registerNew(string $name, string $email, string $password, string $locale, ?array $policyReleaseIds = null): ?int;
}
