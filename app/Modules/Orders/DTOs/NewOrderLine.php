<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** What an order line remembers about the product, as it was at the moment of ordering. Prices come from the PriceBreakdown. */
final readonly class NewOrderLine
{
    /**
     * @param  array<string, string>  $name  locale => name
     * @param  array<string, string>|null  $options  locale => readable options, e.g. ["en" => "Size: M", "bn" => "সাইজ: M"]
     */
    public function __construct(public int $variantId, public string $productType, public array $name, public string $sku, public ?array $options = null) {}
}
