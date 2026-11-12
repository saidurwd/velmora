<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Api;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a guest or a customer; the cart is the caller's own
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('quantity')) {
            $this->merge(['quantity' => BanglaText::numeric((string) $this->input('quantity'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['variant' => ['required', 'string', 'size:26'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:9999']];
    }

    public function quantity(): int
    {
        return (int) ($this->validated('quantity') ?? 1);
    }
}
