<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Api;

use App\Modules\IdentityAccess\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $hash = $this->user()?->password;

        return [
            // People who signed up by phone have no password yet; for them the recent re-confirmation (a code) was the proof.
            'current_password' => $hash !== null
                ? ['required', 'string', 'max:128', fn (string $a, mixed $v, \Closure $fail) => Hash::check((string) $v, $hash) ? null : $fail(__('validation.current_password'))]
                : ['prohibited'],
            'password' => ['required', 'string', new StrongPassword],
        ];
    }
}
