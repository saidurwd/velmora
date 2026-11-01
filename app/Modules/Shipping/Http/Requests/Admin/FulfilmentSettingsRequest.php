<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FulfilmentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['window' => ['required', 'integer', 'min:1', 'max:365'], 'attempts' => ['required', 'integer', 'min:1', 'max:10']];
    }
}
