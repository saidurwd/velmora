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

    /** @param  array<string, mixed>  $v  validated form input (see VariantRequest) */
    public static function fromInput(array $v, bool $isDefault = false): self
    {
        $int = fn (string $k): ?int => ($v[$k] ?? '') === '' ? null : (int) $v[$k];

        return new self(
            (string) ($v['sku'] ?? ''),
            ($v['barcode'] ?? '') === '' ? null : (string) $v['barcode'],
            ! empty($v['track_inventory']),
            $int('weight_g'), $int('length_mm'), $int('width_mm'), $int('height_mm'),
            $int('min_qty') ?? 1, $int('max_qty'), $int('qty_step') ?? 1,
            (string) ($v['status'] ?? 'active'),
            0,
            $isDefault || ! empty($v['is_default']),
            array_values(array_map('intval', array_filter((array) ($v['options'] ?? []), fn ($o) => $o !== null && $o !== ''))),
        );
    }
}
