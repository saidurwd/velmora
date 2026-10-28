<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $birth = BanglaText::numeric((string) $this->input('birth_date'));

        $this->merge([
            'name' => BanglaText::input((string) $this->input('name')),
            'birth_date' => $birth === '' ? null : $birth,
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }
}
