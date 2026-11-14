<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\IdentityAccess\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/** A signed-in staff member changes their own password: the current one proves it is them; the new one follows the same rules as everywhere else. */
class StaffChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:admin'],
            // StrongPassword also compares the new password with the account's own email and phone (see prepareForValidation)
            'password' => ['required', 'string', 'confirmed', 'different:current_password', new StrongPassword],
        ];
    }

    /** The account's own email and phone, for StrongPassword ("not derived from them"): always the account's, whatever the form sent. */
    protected function prepareForValidation(): void
    {
        $user = $this->user('admin');
        $this->merge(['email' => $user?->email, 'phone' => $user?->phone]);
    }
}
