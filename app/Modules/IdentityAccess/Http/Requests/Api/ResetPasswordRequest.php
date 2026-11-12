<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Api;

use App\Modules\IdentityAccess\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the emailed token is the credential
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:200'], 'email' => ['required', 'email:rfc', 'max:254'], 'password' => ['required', 'string', new StrongPassword]];
    }
}
