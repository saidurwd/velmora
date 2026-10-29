<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\AddressChecker;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Orders\DTOs\CheckoutReview;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Services\CheckoutPricing;
use DomainException;

/**
 * Works out what the review page (and the final submit) is about: the live cart, the address from the draft, and the price for
 * the delivery and payment choice. Both pages call it, so what is shown is what is charged.
 */
final class ReviewCheckout
{
    public function __construct(
        private readonly CartReader $carts,
        private readonly AddressBook $book,
        private readonly AddressChecker $checker,
        private readonly CheckoutPricing $pricing,
    ) {}

    /**
     * @param  array{contact_name: string, contact_phone: string, contact_email: string|null, address: array{mode: string, id?: string, input?: array<string, mixed>}|null, shipping?: string|null, payment?: string|null}  $draft
     *
     * @throws CheckoutException cart_empty | address_missing
     */
    public function handle(CartOwner $owner, ?int $userId, array $draft, string $locale, ?string $shipping = null, ?string $payment = null): CheckoutReview
    {
        $cart = $this->carts->view($owner, $locale);
        if ($cart->isEmpty()) {
            throw new CheckoutException('cart_empty');
        }

        $needsDelivery = array_filter($cart->lines, fn (CartLineView $l) => $l->productType === 'physical') !== [];
        $address = $needsDelivery ? $this->address($userId, $draft['address'] ?? null) : null;

        // the delivery options depend on where it goes; take the shopper's pick if it still exists, otherwise the first (cheapest listed)
        $shipping ??= $draft['shipping'] ?? null;
        $payment ??= $draft['payment'] ?? 'cod';
        $first = $this->pricing->quote($cart, $address?->districtId, null, $payment, $locale);
        $codes = array_map(fn ($o) => $o->methodCode, $first->shipping->options);
        $shippingCode = $shipping !== null && in_array($shipping, $codes, true) ? $shipping : ($codes[0] ?? null);

        $quote = $this->pricing->quote($cart, $address?->districtId, $shippingCode, $payment, $locale);
        $paymentCode = in_array($payment, $quote->paymentMethods, true) ? $payment : ($quote->paymentMethods[0] ?? $payment);
        if ($paymentCode !== $payment) {
            $quote = $this->pricing->quote($cart, $address?->districtId, $shippingCode, $paymentCode, $locale);
        }

        return new CheckoutReview($cart, $address, $quote, $shippingCode, $paymentCode, $draft['contact_name'], $draft['contact_phone'], $draft['contact_email'], $needsDelivery);
    }

    /** @param  array{mode: string, id?: string, input?: array<string, mixed>}|null  $draftAddress */
    private function address(?int $userId, ?array $draftAddress): AddressView
    {
        if ($draftAddress === null) {
            throw new CheckoutException('address_missing');
        }

        if ($draftAddress['mode'] === 'saved') {
            // ownership is part of the lookup: another customer's address id simply is not found
            return ($userId === null ? null : $this->book->find($userId, (string) ($draftAddress['id'] ?? ''))) ?? throw new CheckoutException('address_missing');
        }

        try {
            return $this->checker->check(AddressInput::fromArray($draftAddress['input'] ?? []));
        } catch (DomainException) {
            throw new CheckoutException('address_missing');
        }
    }
}
