<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Import;

/**
 * Outcome of one import run. Errors carry translation keys (not sentences) so each viewer reads them in their own language.
 *
 * @phpstan-type RowResult array{line: int, sku: string, outcome: string, errors: list<array{key: string, params: array<string, string>}>}
 */
final class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $failed = 0;

    /** @var list<array{line: int, sku: string, outcome: string, errors: list<array{key: string, params: array<string, string>}>}> */
    public array $rows = [];

    /** @var list<string> */
    public array $ignoredColumns = [];

    public function total(): int
    {
        return count($this->rows);
    }

    /** @param  list<array{key: string, params: array<string, string>}>  $errors */
    public function add(int $line, string $sku, string $outcome, array $errors = []): void
    {
        $this->rows[] = ['line' => $line, 'sku' => $sku, 'outcome' => $outcome, 'errors' => $errors];

        match ($outcome) {
            'created' => $this->created++,
            'updated' => $this->updated++,
            default => $this->failed++,
        };
    }
}
