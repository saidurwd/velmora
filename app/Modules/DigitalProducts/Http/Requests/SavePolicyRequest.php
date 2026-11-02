<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['rule' => ['required', 'in:non_refundable,until_first_use,within_days'], 'days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'revoke' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean'], 'words' => ['required', 'array', 'max:5'], 'words.*.title' => ['nullable', 'string', 'max:120'], 'words.*.text' => ['nullable', 'string', 'max:2000']];
    }
}
