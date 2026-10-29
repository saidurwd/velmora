<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use Carbon\CarbonImmutable;

/** A coupon as staff define it. Amounts are already in minor units (percent: basis points). */
final readonly class CouponData
{
    /**
     * @param  'percent'|'fixed'|'free_shipping'  $type
     * @param  'all'|'products'|'categories'  $scope
     * @param  list<int>  $productIds
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public ?string $description,
        public string $type,
        public int $value,
        public ?int $maxDiscountMinor,
        public int $minSpendMinor,
        public string $scope,
        public array $productIds,
        public array $categoryIds,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?int $usageLimit,
        public ?int $perCustomerLimit,
        public bool $isActive,
    ) {}
}
