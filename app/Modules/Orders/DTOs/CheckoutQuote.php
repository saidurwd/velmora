<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Payments\DTOs\PaymentMethodOption;
use App\Modules\Pricing\DTOs\PriceBreakdown;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingQuote;

/** What this cart would cost to deliver to this place with this delivery and payment choice. */
final readonly class CheckoutQuote
{
    /**
     * @param  list<string>  $paymentMethods  codes the shopper may pay with, given the delivery choice
     * @param  list<PaymentMethodOption>  $paymentOptions  the same, with labels in the shopper's language
     */
    public function __construct(public PriceBreakdown $breakdown, public ShippingQuote $shipping, public ?ShippingOption $option, public array $paymentMethods, public array $paymentOptions = []) {}
}
