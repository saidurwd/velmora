<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Contracts\TaxRates;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class DatabaseTaxRates implements TaxRates
{
    public function __construct(private readonly Settings $settings, private readonly Clock $clock) {}

    public function classCodes(): array
    {
        return array_values(array_map('strval', DB::table('tax_classes')->orderBy('code')->pluck('code')->all()));
    }

    public function rateBp(string $taxClassCode, ?CarbonInterface $on = null, string $jurisdiction = 'BD'): int
    {
        if (! $this->settings->bool('tax.enabled', false)) {
            return 0; // owner is not VAT-registered yet: nothing is ever taxed until this is switched on
        }

        // rates are valid on Dhaka calendar days, not UTC days
        $date = ($on ?? $this->clock->now())->copy()->setTimezone((string) config('app.display_timezone'))->toDateString();

        $rate = DB::table('tax_rates')
            ->join('tax_classes', 'tax_classes.id', '=', 'tax_rates.tax_class_id')
            ->where('tax_classes.code', $taxClassCode)
            ->where('tax_rates.jurisdiction', $jurisdiction)
            ->where('tax_rates.valid_from', '<=', $date)
            ->where('tax_rates.valid_to', '>', $date) // valid_to is exclusive
            ->value('tax_rates.rate_bp');

        return $rate === null ? 0 : (int) $rate;
    }
}
