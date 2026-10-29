<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\DTOs\PriceBreakdown;

/** Everything needed to write an order. The numbers all come from `breakdown`; nothing is recomputed here. */
final readonly class NewOrder
{
    /**
     * @param  list<NewOrderLine>  $lines  same order as `breakdown->lines`
     * @param  list<NewOrderAddress>  $addresses
     */
    public function __construct(
        public Actor $actor,
        public ?int $userId,
        public string $contactName,
        public string $contactPhone,
        public ?string $contactEmail,
        public OrderChannel $channel,
        public string $locale,
        public string $paymentMethodCode,
        public ?string $shippingMethodCode,
        public PriceBreakdown $breakdown,
        public array $lines,
        public array $addresses,
        public bool $needsReview = false,
        public ?string $placementKey = null,
    ) {}
}
