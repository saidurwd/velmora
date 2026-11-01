<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OpenCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['location' => ['required', 'string', 'max:40'], 'skus' => ['nullable', 'string', 'max:4000'], 'note' => ['nullable', 'string', 'max:255']];
    }
}
