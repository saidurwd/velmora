<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Cart\DTOs\CartView;
use App\Modules\Customers\DTOs\AddressView;

/** Everything the review page shows, worked out from the draft and the live cart. */
final readonly class CheckoutReview
{
    public function __construct(
        public CartView $cart,
        public ?AddressView $address,
        public CheckoutQuote $quote,
        public ?string $shippingCode,
        public string $paymentCode,
        public string $contactName,
        public string $contactPhone,
        public ?string $contactEmail,
        public bool $needsDelivery,
        public bool $needsPhoneConfirmation = false,
    ) {}
}
