<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null; // the account is always the signed-in one: the form carries no customer id
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'accept_policies' => ['accepted'],
            'policy_releases' => ['required', 'array', 'max:10'],
            'policy_releases.*' => ['integer', 'min:1'],
        ];
    }

    /** @return list<int> */
    public function releases(): array
    {
        return array_values(array_map('intval', (array) $this->validated('policy_releases')));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['accept_policies.accepted' => __('cms::policies.errors.policies_required')];
    }
}
