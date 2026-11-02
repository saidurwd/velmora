<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\DTOs;

use Carbon\CarbonImmutable;

/** A short-lived address a customer's browser goes to, and nothing more. It is never stored or logged. */
final readonly class DownloadLink
{
    public function __construct(public string $url, public string $filename, public ?int $remaining, public CarbonImmutable $validUntil) {}
}
