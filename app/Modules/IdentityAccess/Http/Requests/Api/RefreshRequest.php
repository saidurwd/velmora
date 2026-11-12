<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the refresh token is the credential
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['refresh_token' => ['required', 'string', 'max:200']];
    }
}
