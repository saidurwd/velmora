<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustEntitlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['action' => ['required', 'in:extend,add_downloads,reset'], 'amount' => ['nullable', 'integer', 'min:1', 'max:3650']];
    }
}
