<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Api;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class SetQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        return ['quantity' => ['required', 'integer', 'min:0', 'max:9999']]; // 0 takes the line out
    }
}
