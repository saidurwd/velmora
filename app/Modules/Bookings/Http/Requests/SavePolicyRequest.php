<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

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
        return [
            'late' => ['required', 'string', 'max:10'], 'no_show' => ['required', 'string', 'max:10'], 'reschedule_hours' => ['required', 'integer', 'min:0', 'max:8760'], 'max_reschedules' => ['required', 'integer', 'min:0', 'max:20'],
            'is_default' => ['nullable', 'boolean'], 'windows' => ['nullable', 'array', 'max:20'], 'windows.*.hours' => ['nullable', 'string', 'max:6'], 'windows.*.fee' => ['nullable', 'string', 'max:10'],
            'words' => ['required', 'array', 'max:10'], 'words.*.title' => ['nullable', 'string', 'max:120'], 'words.*.text' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
