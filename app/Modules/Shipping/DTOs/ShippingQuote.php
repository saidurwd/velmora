<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/**
 * `required` is false for carts with nothing to ship (digital / service only): there are no options and no cost.
 * When shipping IS required but `options` is empty, nothing can deliver to that place right now.
 */
final readonly class ShippingQuote
{
    /** @param  list<ShippingOption>  $options */
    public function __construct(public bool $required, public array $options, public ?string $zoneName = null) {}

    public function option(string $methodCode): ?ShippingOption
    {
        foreach ($this->options as $o) {
            if ($o->methodCode === $methodCode) {
                return $o;
            }
        }

        return null;
    }

    public function canDeliver(): bool
    {
        return ! $this->required || $this->options !== [];
    }
}
