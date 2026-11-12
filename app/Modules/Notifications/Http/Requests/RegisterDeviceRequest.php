<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer; the device is always filed under the caller
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'push_token' => ['required', 'string', 'min:32', 'max:4096'],
            'platform' => ['required', 'in:android,ios'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
