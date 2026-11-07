<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\CodAgingLine;

/** Read-only, for the Reporting module. */
interface CodAgingFigures
{
    /** @return list<CodAgingLine> both kinds, every bucket (oldest bucket last), as of now */
    public function lines(): array;
}
