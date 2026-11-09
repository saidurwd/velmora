<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** The collections the home page may feature, for the staff who choose them. Only active collections count. */
interface HomeCollections
{
    /** @return array<string, string> public id => name in the language, active collections only (capped at 200) */
    public function options(string $locale): array;

    public function exists(string $publicId): bool;
}
