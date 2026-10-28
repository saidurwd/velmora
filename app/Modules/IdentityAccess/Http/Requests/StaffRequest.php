<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route; escalation rules live in the Actions
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => BanglaText::input((string) $this->input('name')), 'email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $editing = $this->route('staff') instanceof User ? $this->route('staff') : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => $editing ? ['prohibited'] : ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'admin')],
            'locale' => ['sometimes', Rule::in(config('app.supported_locales'))],
        ];
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_map('strval', (array) $this->validated('roles', [])));
    }
}
