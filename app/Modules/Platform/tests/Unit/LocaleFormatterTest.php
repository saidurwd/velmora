<?php

declare(strict_types=1);

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Fmt;
use App\Modules\Platform\Support\Grouping;
use App\Modules\Platform\Support\Settings;
use Carbon\CarbonImmutable;

function useLocale(string $locale, bool $banglaDigits = false): void
{
    app()->setLocale($locale);
    app(Settings::class)->set('display.bangla_digits', $banglaDigits);
}

it('groups digits internationally for English and lakh/crore for Bangla', function () {
    expect(Grouping::western('1250000'))->toBe('1,250,000')
        ->and(Grouping::lakhCrore('1250000'))->toBe('12,50,000')
        ->and(Grouping::lakhCrore('999'))->toBe('999')
        ->and(Grouping::lakhCrore('12345678901'))->toBe('12,34,56,78,901');
});

it('formats numbers per locale', function () {
    useLocale('en');
    expect(Fmt::number(1250000.5, 2))->toBe('1,250,000.50')->and(Fmt::number(-1234))->toBe('-1,234');

    useLocale('bn');
    expect(Fmt::number(1250000.5, 2))->toBe('12,50,000.50');

    useLocale('bn', true);
    expect(Fmt::number(1250000))->toBe('১২,৫০,০০০');
});

it('formats money per locale', function () {
    useLocale('en');
    expect(Fmt::money(Money::of(125000000)))->toBe('৳ 1,250,000.00');

    useLocale('bn');
    expect(Fmt::money(Money::of(125000000)))->toBe('৳ 12,50,000.00');

    useLocale('bn', true);
    expect(Fmt::money(Money::of(125000000)))->toBe('৳ ১২,৫০,০০০.০০');
});

it('shows dates in Asia/Dhaka even though they are stored in UTC', function () {
    $utc = CarbonImmutable::parse('2026-03-01 20:30:00', 'UTC'); // = 2 Mar, 02:30 in Dhaka

    useLocale('en');
    expect(Fmt::date($utc))->toBe('2 Mar 2026')->and(Fmt::dateTime($utc))->toBe('2 Mar 2026, 02:30 AM');

    useLocale('bn');
    expect(Fmt::date($utc))->toContain('মার্চ');

    useLocale('bn', true);
    expect(Fmt::date($utc))->toContain('২')->and(Fmt::date($utc))->not->toMatch('/[0-9]/');
});
