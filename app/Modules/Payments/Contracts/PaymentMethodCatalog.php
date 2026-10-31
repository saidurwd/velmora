<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\PaymentMethodOption;

/** The payment methods the shop offers, as the owner configured them. Checkout asks this; it never hard-codes a method. */
interface PaymentMethodCatalog
{
    /**
     * What a shopper may choose for an order of this value with these kinds of items: enabled, inside the method's amount limits,
     * allowed for the product types, not switched off by the payment freeze, and (for online methods) with a gateway that is ready.
     * Whether cash on delivery is possible to THIS address is for the shipping side to decide.
     *
     * @param  list<string>  $productTypes  physical | digital | service
     * @return list<PaymentMethodOption>
     */
    public function options(int $orderTotalMinor, array $productTypes, string $locale): array;

    /** A method's label in this language (or its code if it has none): for pages that show the method an order was paid with. */
    public function label(string $code, string $locale): string;
}
