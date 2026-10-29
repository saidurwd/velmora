<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the cart and draft come from the shopper's own cookie / session
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'shipping' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
            'payment' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
            'expected_total' => ['required', 'string', 'regex:/^\d{1,12}$/'],
            'place_token' => ['required', 'string', 'size:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('expected_total')) {
            $this->merge(['expected_total' => BanglaText::numeric((string) $this->input('expected_total'))]);
        }
    }
}
