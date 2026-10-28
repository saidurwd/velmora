<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Platform\DTOs\Actor;

/** Opening stock for a variant, written once: used by CSV import so re-importing a file never double-counts. */
interface StockInitialiser
{
    /** @return bool true when the opening balance was written; false when the variant already has stock history (nothing changed) */
    public function openingStock(Actor $actor, int $variantId, int $quantity, string $locationCode = 'main'): bool;
}
