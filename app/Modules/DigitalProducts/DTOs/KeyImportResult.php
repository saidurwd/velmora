<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\DTOs;

/** What an import of keys did. The keys themselves are never in here. */
final readonly class KeyImportResult
{
    public function __construct(public int $added, public int $duplicates, public int $invalid, public int $filled) {}
}
