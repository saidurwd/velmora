<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

final readonly class VariantData
{
    /** @param  list<int>  $optionValueIds  attribute_value ids, one per option attribute */
    public function __construct(
        public string $sku,
        public ?string $barcode = null,
        public bool $trackInventory = false,
        public ?int $weightG = null,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
        public int $minQty = 1,
        public ?int $maxQty = null,
        public int $qtyStep = 1,
        public string $status = 'active',
        public int $sortOrder = 0,
        public bool $isDefault = false,
        public array $optionValueIds = [],
    ) {}
}
