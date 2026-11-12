<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

/** Response shapes shared by every module's API documentation (PHPDoc type syntax, read by the OpenAPI generator). */
final class ApiTypes
{
    public const MONEY = 'array{amount_minor: int, currency: string, formatted: string}';

    /** The page envelope of a list: which page, how big, how many in all. */
    public const PAGE = 'array{page: int, per_page: int, total: int, last_page: int}';

    public const LINKS = 'array{prev: string|null, next: string|null}';
}
