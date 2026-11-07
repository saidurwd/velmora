<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\CodAgingFigures;
use App\Modules\Payments\DTOs\CodAgingLine;

final class DatabaseCodAgingFigures implements CodAgingFigures
{
    public function __construct(private readonly CodAging $aging) {}

    public function lines(): array
    {
        $out = [];
        foreach ($this->aging->report() as $kind => $buckets) {
            foreach ($buckets as $bucket => $figures) {
                $out[] = new CodAgingLine($kind, (string) $bucket, $figures['count'], $figures['minor']);
            }
        }

        return $out;
    }
}
