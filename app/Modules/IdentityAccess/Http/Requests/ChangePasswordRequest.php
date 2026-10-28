<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\IdentityAccess\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $hasPassword = $this->user('web')?->password !== null;

        return [
            // People who signed up by phone have no password yet; for them recent re-authentication (OTP) was the proof.
            'current_password' => $hasPassword ? ['required', 'string', 'current_password:web'] : ['prohibited'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword],
        ];
    }
}
