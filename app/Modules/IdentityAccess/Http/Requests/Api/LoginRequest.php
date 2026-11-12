<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public; abuse is handled by the throttle and by the Action
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'in:android,ios'],
        ];
    }
}
