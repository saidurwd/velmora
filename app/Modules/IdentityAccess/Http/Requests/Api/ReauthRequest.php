<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Api;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

/** Either the password or the code sent to the verified phone: exactly one. */
class ReauthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => BanglaText::numeric((string) $this->input('code'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required_without:code', 'prohibits:code', 'nullable', 'string', 'max:128'],
            'code' => ['required_without:password', 'prohibits:password', 'nullable', 'digits:6'],
        ];
    }
}
