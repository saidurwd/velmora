<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Exceptions\CartException;
use App\Modules\Catalog\DTOs\PurchasableVariant;

/** The quantity rules one variant imposes, in one place (add, set quantity, merge). Stock here is advisory: the real reservation happens at checkout. */
final class CartRules
{
    public const MAX_PER_LINE = 100;

    public const MAX_LINES = 50;

    /** @throws CartException */
    public function assertQuantity(PurchasableVariant $v, int $quantity, ?int $available): void
    {
        if (! $v->isPurchasable) {
            throw new CartException('unavailable');
        }

        if ($quantity < $v->minQty) {
            throw new CartException('min_qty', ['min' => $v->minQty]);
        }

        if (($quantity - $v->minQty) % $v->qtyStep !== 0) {
            throw new CartException('qty_step', ['min' => $v->minQty, 'step' => $v->qtyStep]);
        }

        $max = min($v->maxQty ?? self::MAX_PER_LINE, self::MAX_PER_LINE);
        if ($quantity > $max) {
            throw new CartException('max_qty', ['max' => $max]);
        }

        if ($v->trackInventory && $available !== null && $quantity > $available) {
            throw new CartException('insufficient_stock', ['available' => max(0, $available)]);
        }
    }
}
