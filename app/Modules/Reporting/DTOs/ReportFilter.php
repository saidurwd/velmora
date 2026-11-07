<?php

declare(strict_types=1);

namespace App\Modules\Reporting\DTOs;

/** What a report covers: a period of report days (both ends included) and, optionally, how the orders were placed. */
final readonly class ReportFilter
{
    public function __construct(public string $from, public string $to, public ?string $channel = null, public ?string $method = null, public ?string $locale = null) {}

    /** @return array<string, string> the choices that were made, for links and file names */
    public function query(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'channel' => $this->channel, 'method' => $this->method, 'locale' => $this->locale], fn ($v) => $v !== null && $v !== '');
    }
}
