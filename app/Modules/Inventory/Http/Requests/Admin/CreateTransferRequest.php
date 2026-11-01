<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['from' => ['required', 'string', 'max:40'], 'to' => ['required', 'string', 'max:40'], 'lines' => ['required', 'array', 'max:50'], 'lines.*.sku' => ['nullable', 'string', 'max:64'], 'lines.*.qty' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'note' => ['nullable', 'string', 'max:255']];
    }
}
