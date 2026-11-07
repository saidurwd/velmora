<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

/** A report day is a calendar day in the shop's zone (Dhaka): it starts and ends at local midnight, which is a different moment in UTC (where times are stored). */
final class ReportDays
{
    public function __construct(private readonly Clock $clock) {}

    public function zone(): string
    {
        return (string) config('reporting.timezone');
    }

    /** Today's date (Y-m-d) in the shop's zone. */
    public function today(): string
    {
        return $this->clock->now()->setTimezone($this->zone())->format('Y-m-d');
    }

    public function isDay(string $day): bool
    {
        try {
            $this->parse($day);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the day's first moment (included) and the next day's first moment (excluded), in UTC */
    public function bounds(string $day): array
    {
        $start = $this->parse($day);

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** @return list<string> every day from `$from` to `$to`, both included (empty when `$to` is before `$from`) */
    public function range(string $from, string $to): array
    {
        $out = [];
        $last = $this->parse($to);
        for ($day = $this->parse($from); $day <= $last; $day = $day->addDay()) {
            $out[] = $day->format('Y-m-d');
        }

        return $out;
    }

    public function shift(string $day, int $days): string
    {
        return $this->parse($day)->addDays($days)->format('Y-m-d');
    }

    private function parse(string $day): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->zone());
        } catch (InvalidFormatException) {
            $parsed = null;   // not a date at all ("soon")
        }
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException("not a day: {$day}");   // or one that does not exist ("2026-02-30" would become 2 March)
        }

        return $parsed;
    }
}
