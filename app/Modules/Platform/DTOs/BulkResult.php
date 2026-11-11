<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** What a bulk run did, row by row. */
final readonly class BulkResult
{
    /** @param  list<array{id: string, label: string, ok: bool, message: string|null}>  $items */
    public function __construct(public string $action, public array $items) {}

    public function done(): int
    {
        return count(array_filter($this->items, fn (array $i): bool => $i['ok']));
    }

    public function failed(): int
    {
        return count($this->items) - $this->done();
    }
}
