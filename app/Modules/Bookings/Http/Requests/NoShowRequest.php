<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NoShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:255'], 'waive' => ['nullable', 'boolean']];
    }
}
