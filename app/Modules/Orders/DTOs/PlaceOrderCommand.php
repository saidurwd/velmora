<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Platform\DTOs\Actor;

/**
 * One checkout submission. The address arrives already validated (from the address book or the address checker);
 * every number is recomputed by PlaceOrder, and `expectedTotalMinor` is only what the shopper was shown.
 */
final readonly class PlaceOrderCommand
{
    public function __construct(
        public Actor $actor,
        public CartOwner $owner,
        public ?int $userId,
        public string $contactName,
        public string $contactPhone,
        public ?string $contactEmail,
        public ?AddressView $address,
        public ?string $shippingMethodCode,
        public string $paymentMethodCode,
        public int $expectedTotalMinor,
        public string $placeToken,
        public string $locale,
        public OrderChannel $channel = OrderChannel::Web,
    ) {}
}
