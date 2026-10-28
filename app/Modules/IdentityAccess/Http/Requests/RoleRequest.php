<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $role = $this->route('role') instanceof Role ? $this->route('role') : null;

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('guard_name', 'admin')->ignore($role?->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ];
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values(array_map('strval', (array) $this->validated('permissions', [])));
    }
}
