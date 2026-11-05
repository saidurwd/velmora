<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Catalog\DTOs\PurchasableVariant;
use App\Modules\Engagement\Exceptions\EngagementException;

/** Finds the variant a customer asked to save, by the public id on the page. Services are booked, not saved; anything not on sale is "not found". */
final class SavableVariants
{
    public function __construct(private readonly PurchasableVariants $variants) {}

    /** @throws EngagementException not_found | service */
    public function resolve(string $publicId, string $locale): PurchasableVariant
    {
        $id = $this->variants->idByPublicId($publicId);
        $variant = $id === null ? null : ($this->variants->details([$id], $locale)[$id] ?? null);
        if ($variant === null || ! $variant->isPurchasable) {
            throw new EngagementException('not_found');
        }
        if ($variant->productType === 'service') {
            throw new EngagementException('service');
        }

        return $variant;
    }
}
