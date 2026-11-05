<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class SavePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['quiet_from', 'quiet_to'] as $key) {
            $value = BanglaText::numeric((string) $this->input($key));   // Bangla digits become ASCII
            $this->merge([$key => $value === '' ? null : $value]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_to'],
            'quiet_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_from', 'different:quiet_from'],
        ];
    }
}
