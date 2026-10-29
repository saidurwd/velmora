<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutChoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'shipping' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
            'payment' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
        ];
    }
}
