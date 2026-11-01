<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['received' => ['nullable', 'array', 'max:100'], 'received.*' => ['nullable', 'integer', 'min:0', 'max:100000']];
    }
}
