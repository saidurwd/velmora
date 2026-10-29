<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\Contracts\CartWriter;
use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\AddressFingerprint;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Orders\Services\CodRiskAssessor;
use App\Modules\Orders\Services\PhoneLock;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Exceptions\InvalidPhoneNumber;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\CouponRedeemer;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Turns a cart into an order, all or nothing, in ONE transaction:
 *   re-read the cart → re-price it (the shown total is only checked, never trusted) → write the order → hold the stock
 *   (one conditional UPDATE per line) → use up the coupons → empty the cart → record the OrderPlaced event.
 * If anything fails, nothing stays: no order, no held stock, no used coupon, the cart is untouched.
 * Submitting the same checkout twice (double-click, retry, two tabs) yields the same order: the form's one-time token is
 * part of a UNIQUE key on the order, so even two submissions at the same instant can only make one.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly CartReader $carts,
        private readonly CartWriter $cartWriter,
        private readonly CheckoutPricing $pricing,
        private readonly CreateOrder $create,
        private readonly StockReservations $stock,
        private readonly CouponRedeemer $coupons,
        private readonly PurchasableVariants $variants,
        private readonly Outbox $outbox,
        private readonly CodRiskAssessor $risk,
        private readonly PhoneLock $phoneLock,
    ) {}

    /** @throws CheckoutException */
    public function handle(PlaceOrderCommand $command): Order
    {
        $key = $this->placementKey($command);

        $existing = $this->find($key);
        if ($existing !== null) {
            return $existing; // this very submission was already placed
        }

        try {
            $phone = PhoneNumber::parse($command->contactPhone)->e164();
        } catch (InvalidPhoneNumber) {
            throw new CheckoutException('phone_invalid');
        }

        // one checkout at a time per phone, so "at most N open cash orders" cannot be beaten by pressing the button twice at once
        return $this->phoneLock->run($phone, function () use ($command, $key): Order {
            $existing = $this->find($key); // the same submission may have finished while we waited for the lock
            if ($existing !== null) {
                return $existing;
            }

            try {
                return Transactions::run(fn (): Order => $this->place($command, $key));
            } catch (UniqueConstraintViolationException $e) {
                // someone submitted the same checkout at the same instant and won: that is the order
                return $this->find($key) ?? throw $e;
            }
        });
    }

    private function find(string $key): ?Order
    {
        return Order::query()->where('placement_key', $key)->first();
    }

    private function place(PlaceOrderCommand $c, string $key): Order
    {
        try {
            $phone = PhoneNumber::parse($c->contactPhone)->e164();
        } catch (InvalidPhoneNumber) {
            throw new CheckoutException('phone_invalid');
        }

        $cart = $this->carts->view($c->owner, $c->locale);
        if ($cart->isEmpty()) {
            throw new CheckoutException('cart_empty');
        }
        if ($cart->hasProblems()) {
            throw new CheckoutException('cart_problem');
        }

        $needsDelivery = array_filter($cart->lines, fn (CartLineView $l) => $l->productType === 'physical') !== [];
        if ($needsDelivery && $c->address === null) {
            throw new CheckoutException('address_required');
        }

        $quote = $this->pricing->quote($cart, $c->address?->districtId, $c->shippingMethodCode, $c->paymentMethodCode, $c->locale);
        if ($quote->shipping->required && $quote->shipping->options === []) {
            throw new CheckoutException('cannot_deliver');
        }
        if ($quote->shipping->required && $quote->option === null) {
            throw new CheckoutException('shipping_method_invalid');
        }
        if (! in_array($c->paymentMethodCode, $quote->paymentMethods, true)) {
            throw new CheckoutException('payment_method_invalid');
        }
        if ($quote->breakdown->grandTotalMinor !== $c->expectedTotalMinor) {
            throw new CheckoutException('totals_changed', ['total' => $quote->breakdown->grandTotalMinor]); // prices, stock-driven sales or a code changed since the page was shown
        }

        $risk = $this->risk->assess($phone, $c->address === null ? null : AddressFingerprint::of($c->address->addressLine, $c->address->area, $c->address->upazilaId), $quote->breakdown->grandTotalMinor, $c->paymentMethodCode, $c->phoneConfirmed);
        if ($risk->blocked) {
            Log::warning('orders.blocked_attempt', ['phone' => PhoneNumber::parse($phone)->masked()]);

            throw new CheckoutException('not_accepted'); // deliberately says nothing about why
        }
        if ($risk->tooManyOpenCod) {
            throw new CheckoutException('too_many_open_cod', ['max' => $risk->maxOpenCod]);
        }
        if ($risk->needsPhoneConfirmation) {
            throw new CheckoutException('phone_confirmation_required');
        }

        $buy = array_values(array_filter($cart->lines, fn (CartLineView $l) => $l->problem !== 'unavailable'));
        $names = $this->namesByLocale(array_map(fn (CartLineView $l) => $l->variantId, $buy));

        $order = $this->create->handle(new NewOrder(
            $c->actor, $c->userId, trim(BanglaText::input($c->contactName)), $phone, $this->email($c->contactEmail), $c->channel, $c->locale,
            $c->paymentMethodCode, $quote->option?->methodCode, $quote->breakdown,
            array_map(fn (CartLineView $l) => new NewOrderLine($l->variantId, $l->productType, $names[$l->variantId]['name'], $l->sku, $names[$l->variantId]['options']), $buy),
            $c->address === null ? [] : [new NewOrderAddress(
                'shipping', $c->address->recipient, $c->address->phone, $c->address->divisionId, $c->address->districtId, $c->address->upazilaId,
                ['division' => $c->address->division, 'district' => $c->address->district, 'upazila' => $c->address->upazila],
                $c->address->addressLine, $c->address->postalCode, $c->address->area, $c->address->landmark, $c->address->deliveryNote,
            )],
            $risk->needsReview(), $key, $risk->flags,
        ), (bool) config('orders.auto_confirm_cod') && $c->paymentMethodCode === 'cod' && ! $risk->needsReview() ? OrderStatus::Confirmed : OrderStatus::Pending);

        // the stock: all lines or none, decided by one conditional UPDATE per line
        $tracked = [];
        foreach ($buy as $l) {
            if ($l->available !== null) {
                $tracked[$l->variantId] = $l->quantity;
            }
        }
        try {
            $this->stock->holdForOrder($tracked, $order->public_id);
        } catch (DomainException) {
            throw new CheckoutException('insufficient_stock');
        }

        // the coupons: each use is one conditional UPDATE; the per-customer limit is checked as the shopper is now known
        if ($cart->discounts !== null) {
            try {
                $this->coupons->redeem($cart->discounts, $order->id, $c->userId !== null ? 'u:'.$c->userId : 'p:'.hash('sha256', $phone));
            } catch (DomainException $e) {
                throw new CheckoutException('coupon_failed', ['detail' => $e->getMessage()]);
            }
        }

        $this->cartWriter->clear($c->owner);
        $this->outbox->record(new OrderPlaced($order->id, $order->public_id, $order->order_number, $c->locale));

        return $order;
    }

    private function placementKey(PlaceOrderCommand $c): string
    {
        $who = $c->userId !== null ? 'u:'.$c->userId : 'g:'.hash('sha256', (string) $c->owner->guestToken);

        return hash('sha256', $who.'|'.$c->placeToken);
    }

    private function email(?string $email): ?string
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));

        return $email === '' ? null : mb_substr($email, 0, 190);
    }

    /**
     * Name and options in every supported language, for the order's snapshot.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{name: array<string, string>, options: array<string, string>|null}>
     */
    private function namesByLocale(array $variantIds): array
    {
        $out = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            foreach ($this->variants->details($variantIds, (string) $locale) as $id => $d) {
                $out[$id]['name'][(string) $locale] = $d->productName;
                if ($d->optionsLabel !== null && $d->optionsLabel !== '') {
                    $out[$id]['options'][(string) $locale] = $d->optionsLabel;
                }
            }
        }

        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = ['name' => $out[$id]['name'] ?? ['en' => ''], 'options' => $out[$id]['options'] ?? null];
        }

        return $result;
    }
}
