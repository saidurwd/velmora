<?php

declare(strict_types=1);

namespace App\Modules\Cms\DTOs;

use Carbon\CarbonImmutable;

/** One policy a person is asked to accept: the release they would be accepting, and how to read it. */
final readonly class PolicyNotice
{
    public function __construct(
        public string $kind,
        public int $releaseId,
        public int $versionNumber,
        public string $title,
        public ?string $url,
        public CarbonImmutable $effectiveAt,
    ) {}
}
