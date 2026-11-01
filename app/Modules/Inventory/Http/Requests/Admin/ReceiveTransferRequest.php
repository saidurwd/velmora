<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['received' => ['nullable', 'array', 'max:50'], 'received.*' => ['nullable', 'integer', 'min:0', 'max:1000000']];
    }
}
