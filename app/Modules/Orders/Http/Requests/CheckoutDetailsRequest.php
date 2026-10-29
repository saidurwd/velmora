<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Contact and address as typed. The place names are checked as a real division → district → upazila chain by the address checker. */
class CheckoutDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // open to guests; a saved address is only found for its owner
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $phone = fn () => function (string $attribute, mixed $value, Closure $fail): void {
            if (PhoneNumber::tryParse(BanglaText::digitsToAscii((string) $value)) === null) {
                $fail(__('customers::account.addresses.errors.phone_invalid'));
            }
        };

        return [
            'saved_address' => ['nullable', 'string', 'size:26'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            // contact (carts with nothing to deliver)
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:30', $phone()],
            // a typed address
            'recipient' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', $phone()],
            'division_id' => ['nullable', 'integer'],
            'district_id' => ['nullable', 'integer'],
            'upazila_id' => ['nullable', 'integer'],
            'area' => ['nullable', 'string', 'max:120'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'save_address' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['division_id', 'district_id', 'upazila_id'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => BanglaText::numeric((string) $this->input($key))]);
            }
        }
    }
}
