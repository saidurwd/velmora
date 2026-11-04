<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'string', 'max:10'], 'closed' => ['nullable', 'boolean'], 'start' => ['nullable', 'string', 'max:8'], 'end' => ['nullable', 'string', 'max:8'], 'note' => ['nullable', 'string', 'max:190'],
        ];
    }
}
