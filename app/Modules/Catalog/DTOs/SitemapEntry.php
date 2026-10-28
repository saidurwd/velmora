<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use Carbon\CarbonImmutable;

/** One public address that exists in one or more languages. */
final readonly class SitemapEntry
{
    /** @param  array<string, string>  $paths  locale => absolute path starting with "/" (already percent-encoded) */
    public function __construct(public array $paths, public ?CarbonImmutable $lastModified = null) {}
}
