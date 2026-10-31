<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\IdentityAccess\Contracts\CustomerRegistration;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class CreateAccountFromOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the signed link on the form is the proof; see the route
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => BanglaText::input((string) $this->input('name')), 'email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // no `unique` rule on purpose: it would reveal which addresses have an account
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'confirmed', app(CustomerRegistration::class)->passwordRule()],
        ];
    }
}
