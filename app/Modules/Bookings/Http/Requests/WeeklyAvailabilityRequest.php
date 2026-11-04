<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WeeklyAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rules' => ['nullable', 'array', 'max:100'], 'rules.*.weekday' => ['nullable', 'integer', 'min:1', 'max:7'], 'rules.*.start' => ['nullable', 'string', 'max:8'], 'rules.*.end' => ['nullable', 'string', 'max:8'],
            'rules.*.valid_from' => ['nullable', 'string', 'max:10'], 'rules.*.valid_to' => ['nullable', 'string', 'max:10'],
        ];
    }
}
