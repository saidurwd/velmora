<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Cart\DTOs\CartLineView;
use App\Modules\Cart\DTOs\CartView;
use App\Modules\Orders\DTOs\CheckoutQuote;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Pricing\Contracts\CartPricer;
use App\Modules\Pricing\DTOs\PricingContext;
use App\Modules\Pricing\DTOs\PricingLine;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\DTOs\ShippingRequest;

/** The one place checkout works out what an order costs: the review page shows it and PlaceOrder charges it. */
final class CheckoutPricing
{
    public function __construct(private readonly CartPricer $pricer, private readonly ShippingQuoter $shipping, private readonly PaymentMethodCatalog $methods) {}

    public function quote(CartView $cart, ?int $districtId, ?string $shippingMethodCode, string $paymentMethodCode, string $locale): CheckoutQuote
    {
        $shipping = $this->shipping->quote(ShippingRequest::fromCart($cart, $districtId, $cart->freeShipping()), $locale);
        $option = $shipping->required && $shippingMethodCode !== null ? $shipping->option($shippingMethodCode) : null;

        $codFee = $paymentMethodCode === CodPaymentInitiator::CODE && $option !== null && $option->codAvailable ? $option->codFee->amountMinor : 0;

        $discounts = $cart->discounts;
        $lines = array_values(array_map(
            fn (CartLineView $l) => new PricingLine($l->variantId, $l->taxClassCode, $l->quantity, $discounts->lineDiscounts[$l->variantId] ?? 0),
            array_filter($cart->lines, fn (CartLineView $l) => $l->problem !== 'unavailable'),
        ));

        $breakdown = $this->pricer->price(new PricingContext($lines, $discounts->orderDiscountMinor ?? 0, $option?->price->amountMinor ?? 0, $codFee));

        // What the shopper may pay with: the methods the owner switched on for this value and these items; cash on delivery also needs
        // something to deliver and a delivery choice that allows it.
        $types = array_values(array_unique(array_map(fn (CartLineView $l) => $l->productType, $cart->lines)));
        $options = array_values(array_filter(
            $this->methods->options($breakdown->grandTotalMinor - $breakdown->codFeeMinor, $types, $locale),
            fn ($m) => ! $m->isCash() || ($option !== null && $option->codAvailable),
        ));

        return new CheckoutQuote($breakdown, $shipping, $option, array_map(fn ($m) => $m->code, $options), $options);
    }
}
