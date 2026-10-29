<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // routes sit behind auth:web; ownership is enforced by AddressBook
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40'],
            'recipient' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'division_id' => ['required', 'integer', 'exists:divisions,id'],
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'upazila_id' => ['required', 'integer', 'exists:upazilas,id'],
            'area' => ['nullable', 'string', 'max:120'],
            'address_line' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'make_default' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Bangla digits are accepted in the numeric choices
        foreach (['division_id', 'district_id', 'upazila_id'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => BanglaText::numeric((string) $this->input($key))]);
            }
        }
    }

    public function address(): AddressInput
    {
        return AddressInput::fromArray($this->validated());
    }
}
