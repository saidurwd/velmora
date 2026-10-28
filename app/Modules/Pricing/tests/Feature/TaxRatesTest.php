<?php

declare(strict_types=1);

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Contracts\TaxRates;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(TaxClassSeeder::class);
    $this->standard = DB::table('tax_classes')->where('code', 'standard')->value('id');
});

function addRate(int $classId, int $bp, string $from, string $to = '9999-12-31', string $jurisdiction = 'BD'): void
{
    DB::table('tax_rates')->insert(['tax_class_id' => $classId, 'jurisdiction' => $jurisdiction, 'rate_bp' => $bp, 'valid_from' => $from, 'valid_to' => $to, 'created_at' => now(), 'updated_at' => now()]);
}

it('seeds tax classes (idempotently) but never any rates', function () {
    $this->seed(TaxClassSeeder::class);

    expect(DB::table('tax_classes')->count())->toBe(3)->and(DB::table('tax_rates')->count())->toBe(0)
        ->and(DB::table('tax_classes')->where('is_default', true)->value('code'))->toBe('standard');
});

describe('database guarantees', function () {
    it('rejects overlapping rates for the same class and jurisdiction', function () {
        addRate($this->standard, 1500, '2026-01-01', '2027-01-01'); // valid through 31 Dec 2026 (end is exclusive)

        expect(fn () => addRate($this->standard, 1000, '2026-06-01', '2027-06-30'))->toThrow(QueryException::class)
            ->and(fn () => addRate($this->standard, 1000, '2026-12-31', '2028-01-01'))->toThrow(QueryException::class)   // shares 31 Dec
            ->and(fn () => addRate($this->standard, 1000, '2025-06-01', '2026-01-02'))->toThrow(QueryException::class);  // shares 1 Jan
    });

    it('allows consecutive rates, other classes and other jurisdictions', function () {
        addRate($this->standard, 1500, '2026-01-01', '2027-01-01');
        addRate($this->standard, 1000, '2027-01-01'); // starts the day the previous one ends: allowed, no gap, no overlap
        addRate(DB::table('tax_classes')->where('code', 'zero_rated')->value('id'), 0, '2026-01-01');
        addRate($this->standard, 500, '2026-01-01', '2027-01-01', 'XX');

        expect(DB::table('tax_rates')->count())->toBe(4);
    });

    it('rejects impossible values', function () {
        expect(fn () => addRate($this->standard, 10001, '2026-01-01'))->toThrow(QueryException::class)
            ->and(fn () => addRate($this->standard, 1500, '2026-05-01', '2026-04-01'))->toThrow(QueryException::class)
            ->and(fn () => addRate($this->standard, 1500, '2026-05-01', '2026-05-01'))->toThrow(QueryException::class); // empty period
    });

    it('keeps history: a rate can be closed but not rewritten or deleted', function () {
        addRate($this->standard, 1500, '2026-01-01');

        DB::table('tax_rates')->update(['valid_to' => '2027-01-01', 'updated_at' => now()]); // closing is allowed
        expect(DB::table('tax_rates')->value('valid_to'))->toBe('2027-01-01');

        expect(fn () => DB::table('tax_rates')->update(['rate_bp' => 500]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('tax_rates')->delete())->toThrow(QueryException::class)
            ->and(DB::table('tax_rates')->value('rate_bp'))->toBe(1500);
    });
});

describe('resolver', function () {
    it('returns 0 while tax is disabled, even when rates exist', function () {
        addRate($this->standard, 1500, '2020-01-01');

        expect(app(TaxRates::class)->rateBp('standard'))->toBe(0);
    });

    it('returns the rate in force once tax is enabled', function () {
        addRate($this->standard, 1500, '2020-01-01', '2027-01-01');
        addRate($this->standard, 1000, '2027-01-01');
        app(Settings::class)->set('tax.enabled', true);

        $rates = app(TaxRates::class);

        expect($rates->rateBp('standard', CarbonImmutable::parse('2026-06-15', 'UTC')))->toBe(1500)
            ->and($rates->rateBp('standard', CarbonImmutable::parse('2027-02-01', 'UTC')))->toBe(1000)
            ->and($rates->rateBp('standard', CarbonImmutable::parse('2019-01-01', 'UTC')))->toBe(0)  // before any rate
            ->and($rates->rateBp('zero_rated', CarbonImmutable::parse('2026-06-15', 'UTC')))->toBe(0)
            ->and($rates->rateBp('unknown_class'))->toBe(0);
    });

    it('switches rates at midnight in Dhaka, not midnight UTC', function () {
        addRate($this->standard, 1500, '2026-01-01', '2026-07-01');
        addRate($this->standard, 1000, '2026-07-01');
        app(Settings::class)->set('tax.enabled', true);
        $rates = app(TaxRates::class);

        // 17:59 UTC on 30 June = 23:59 in Dhaka -> still the old rate
        expect($rates->rateBp('standard', CarbonImmutable::parse('2026-06-30 17:59:00', 'UTC')))->toBe(1500)
            // 18:00 UTC = 00:00 on 1 July in Dhaka -> the new rate
            ->and($rates->rateBp('standard', CarbonImmutable::parse('2026-06-30 18:00:00', 'UTC')))->toBe(1000);
    });

    it('uses the injected clock when no date is given', function () {
        addRate($this->standard, 1500, '2026-01-01', '2026-07-01');
        addRate($this->standard, 1000, '2026-07-01');
        app(Settings::class)->set('tax.enabled', true);

        app()->instance(Clock::class, new FrozenClock('2026-07-01 12:00:00'));

        expect(app(TaxRates::class)->rateBp('standard'))->toBe(1000);
    });
});
