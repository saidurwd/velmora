<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'string', 'in:staff,room,vehicle,team'], 'active' => ['nullable', 'boolean'],
            'services' => ['nullable', 'array', 'max:200'], 'services.*' => ['string', 'max:64'],
        ];
    }
}
