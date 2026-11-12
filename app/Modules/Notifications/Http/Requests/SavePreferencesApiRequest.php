<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

/** Both marketing answers are sent explicitly (the app sends the whole choice every time), so a missing field can never become an accidental "no". */
class SavePreferencesApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer; the answers are always saved for that customer
    }

    protected function prepareForValidation(): void
    {
        foreach (['quiet_from', 'quiet_to'] as $key) {
            $value = BanglaText::numeric((string) $this->input($key));
            $this->merge([$key => $value === '' ? null : $value]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'boolean'],
            'sms' => ['required', 'boolean'],
            'push' => ['sometimes', 'boolean'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_to'],
            'quiet_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_from', 'different:quiet_from'],
        ];
    }
}
