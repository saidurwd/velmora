<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Web;

use App\Modules\Platform\Support\BanglaText;
use App\Modules\Shipping\Actions\RequestReturn;
use Illuminate\Foundation\Http\FormRequest;

class RequestReturnForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    protected function prepareForValidation(): void
    {
        $lines = [];
        foreach ((array) $this->input('lines', []) as $position => $quantity) {
            $q = BanglaText::digitsToAscii((string) $quantity);
            if ($q !== '' && $q !== '0') {
                $lines[(string) $position] = $q;
            }
        }
        $this->merge(['lines' => $lines, 'note' => BanglaText::input((string) $this->input('note'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['integer', 'min:1', 'max:1000'],
            'reason_code' => ['required', 'in:'.implode(',', RequestReturn::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
