<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

/**
 * What a bulk change WOULD do. `fingerprint` hashes the prices the preview was computed from; applying a preview whose
 * source prices changed in the meantime is refused, so staff never apply numbers they did not see.
 *
 * @phpstan-type Row array{variant_id: int, old_minor: int, new_minor: int, delta_minor: int, warning: string|null}
 */
final readonly class BulkPricePreview
{
    /** @param  list<array{variant_id: int, old_minor: int, new_minor: int, delta_minor: int, warning: string|null}>  $rows */
    public function __construct(public array $rows, public string $listCode, public string $fingerprint) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
