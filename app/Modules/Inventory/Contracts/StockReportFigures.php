<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\DTOs\AgedStockLine;
use App\Modules\Inventory\DTOs\StockLine;

/** Read-only, for the Reporting module. Stock in quarantine and places that are switched off are never counted. */
interface StockReportFigures
{
    /**
     * Products at or below their low level, or with nothing left to sell, per place, least left first.
     *
     * @return list<StockLine>
     */
    public function needingAttention(int $limit): array;

    /**
     * The longest-sitting stock, oldest first.
     *
     * @return list<AgedStockLine>
     */
    public function oldest(int $limit): array;
}
