<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RecordCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['counted' => ['required', 'array', 'max:2000'], 'counted.*' => ['nullable', 'integer', 'min:0', 'max:10000000']];
    }
}
