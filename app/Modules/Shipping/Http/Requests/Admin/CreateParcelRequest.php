<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateParcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['quantities' => ['required', 'array', 'max:100'], 'quantities.*' => ['nullable', 'integer', 'min:0', 'max:100000'], 'courier' => ['nullable', 'string', 'size:26', 'alpha_num'], 'note' => ['nullable', 'string', 'max:255']];
    }
}
