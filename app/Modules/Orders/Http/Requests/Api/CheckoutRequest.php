<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Api;

use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the app sends to price or place an order. Contact and address as typed (or a saved address id); the delivery and payment choice; nothing
 * about prices. Field names are the app's own (`contact.name`, `address.district_id`), mapped here to the names the checkout Action reads.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a guest or a customer; the cart is the caller's own, and a saved address is only found for its owner
    }

    protected function prepareForValidation(): void
    {
        foreach (['division_id', 'district_id', 'upazila_id'] as $key) {
            if (is_array($this->input('address')) && isset($this->input('address')[$key])) {
                $this->merge(['address' => [$key => BanglaText::numeric((string) $this->input('address')[$key])] + $this->input('address')]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $phone = fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (PhoneNumber::tryParse(BanglaText::digitsToAscii((string) $value)) === null) {
                $fail(__('customers::account.addresses.errors.phone_invalid'));
            }
        };

        return [
            'contact' => ['nullable', 'array'],
            'contact.name' => ['nullable', 'string', 'max:120'],
            'contact.phone' => ['nullable', 'string', 'max:30', $phone()],
            'contact.email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            'address' => ['nullable', 'array'],
            'address.id' => ['nullable', 'string', 'size:26'],
            'address.recipient' => ['nullable', 'string', 'max:120'],
            'address.phone' => ['nullable', 'string', 'max:30', $phone()],
            'address.division_id' => ['nullable', 'integer'],
            'address.district_id' => ['nullable', 'integer'],
            'address.upazila_id' => ['nullable', 'integer'],
            'address.area' => ['nullable', 'string', 'max:120'],
            'address.address_line' => ['nullable', 'string', 'max:255'],
            'address.landmark' => ['nullable', 'string', 'max:160'],
            'address.delivery_note' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:10'],
            'shipping_method' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
            'payment_method' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
        ];
    }

    /**
     * The fields the draft builder reads, under the names the website's form uses.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $a = (array) $this->validated('address', []);
        $c = (array) $this->validated('contact', []);

        return array_filter([
            'email' => $c['email'] ?? null, 'contact_name' => $c['name'] ?? null, 'contact_phone' => $c['phone'] ?? null,
            'saved_address' => $a['id'] ?? null,
            'recipient' => $a['recipient'] ?? null, 'phone' => $a['phone'] ?? null, 'division_id' => $a['division_id'] ?? null, 'district_id' => $a['district_id'] ?? null, 'upazila_id' => $a['upazila_id'] ?? null,
            'area' => $a['area'] ?? null, 'address_line' => $a['address_line'] ?? null, 'landmark' => $a['landmark'] ?? null, 'delivery_note' => $a['delivery_note'] ?? null, 'postal_code' => $a['postal_code'] ?? null,
        ], fn ($v) => $v !== null);
    }

    public function shippingMethod(): ?string
    {
        return $this->validated('shipping_method');
    }

    public function paymentMethod(): ?string
    {
        return $this->validated('payment_method');
    }
}
