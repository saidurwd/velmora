<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InspectReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['line' => ['required', 'integer', 'min:1'], 'outcome' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:255']];
    }
}
