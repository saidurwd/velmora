<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\AddressChecker;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use DomainException;
use Illuminate\Support\Facades\Validator;

/**
 * Turns what the shopper typed on the details step into the checkout draft that ReviewCheckout and PlaceOrder work from: who to contact, and where to
 * deliver (one of their saved addresses, or a typed one checked as a real division, district and upazila chain). The website keeps the draft in its
 * session; the app sends the same fields with every call. One Action, so both build exactly the same draft.
 */
final class BuildCheckoutDraft
{
    public function __construct(private readonly CartReader $carts, private readonly AddressBook $book, private readonly AddressChecker $checker) {}

    /**
     * @param  array<string, mixed>  $v  email, contact_name, contact_phone, saved_address, recipient, phone, division_id, district_id, upazila_id, area, address_line, landmark, delivery_note, postal_code
     * @return array{contact_name: string, contact_phone: string, contact_email: string|null, address: array{mode: string, id?: string, input?: array<string, mixed>}|null}
     *
     * @throws CheckoutException cart_empty | cart_problem | contact_required (params: field) | address_missing
     * @throws DomainException an address reason from the address checker (phone_invalid, location_mismatch, postal_invalid, limit_reached)
     */
    public function handle(CartOwner $owner, array $v, bool $saveAddress, string $locale): array
    {
        $cart = $this->carts->view($owner, $locale);
        if ($cart->isEmpty()) {
            throw new CheckoutException('cart_empty');
        }
        if ($cart->hasProblems()) {
            throw new CheckoutException('cart_problem');
        }

        $needsDelivery = array_filter($cart->lines, fn (CartLineView $l) => $l->productType === 'physical') !== [];
        $email = ($v['email'] ?? '') === '' ? null : mb_strtolower(trim((string) $v['email']));

        if (! $needsDelivery) {
            $name = trim(BanglaText::input((string) ($v['contact_name'] ?? '')));
            $phone = PhoneNumber::tryParse(BanglaText::digitsToAscii((string) ($v['contact_phone'] ?? '')));
            if ($name === '' || $phone === null) {
                throw new CheckoutException('contact_required', ['field' => $name === '' ? 'contact_name' : 'contact_phone']);
            }

            return ['contact_name' => $name, 'contact_phone' => $phone->e164(), 'contact_email' => $email, 'address' => null];
        }

        $userId = $owner->userId;
        if ($userId !== null && ($v['saved_address'] ?? '') !== '') {
            $address = $this->book->find($userId, (string) $v['saved_address']) ?? throw new CheckoutException('address_missing');

            return ['contact_name' => $address->recipient, 'contact_phone' => $address->phone, 'contact_email' => $email, 'address' => ['mode' => 'saved', 'id' => $address->publicId]];
        }

        Validator::make($v, [
            'recipient' => ['required'], 'phone' => ['required'], 'division_id' => ['required'], 'district_id' => ['required'], 'upazila_id' => ['required'], 'address_line' => ['required'],
        ], [], [
            'recipient' => __('customers::account.addresses.recipient'), 'phone' => __('customers::account.addresses.phone'), 'division_id' => __('customers::account.addresses.division'),
            'district_id' => __('customers::account.addresses.district'), 'upazila_id' => __('customers::account.addresses.upazila'), 'address_line' => __('customers::account.addresses.line'),
        ])->validate();

        $input = AddressInput::fromArray($v);
        $address = $this->checker->check($input);
        if ($userId !== null && $saveAddress) {
            $address = $this->book->save($userId, null, $input);
        }

        return [
            'contact_name' => $address->recipient, 'contact_phone' => $address->phone, 'contact_email' => $email,
            'address' => $address->publicId !== '' ? ['mode' => 'saved', 'id' => $address->publicId] : ['mode' => 'new', 'input' => $v],
        ];
    }
}
