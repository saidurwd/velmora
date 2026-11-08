<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Orders\Contracts\CustomerOrderMonths;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Reporting\Services\ReportDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Works the customer cohorts out again from the order history and replaces the whole table in one transaction. A signed-in customer's cohort is the shop month of their first live
 * (not cancelled) order in the shop currency; for each later month they ordered in, one is added to that cohort's "months since" row. Guests are not here.
 * The status row is locked first and the orders are read only after getting it, so two rebuilds take turns and the older read can never overwrite the newer.
 */
final class RefreshCohorts
{
    private const CHUNK = 500;

    public function __construct(private readonly CustomerOrderMonths $orders, private readonly ReportDays $days, private readonly Clock $clock) {}

    /** @return array{cohort_rows: int, customers: int} */
    public function handle(): array
    {
        $now = $this->clock->now();

        return Transactions::run(function () use ($now): array {
            DB::statement('INSERT INTO report_cohort_status (id, refreshed_at, cohort_rows, customers) VALUES (1, ?, 0, 0) ON DUPLICATE KEY UPDATE id = id', [$now]);

            $offset = CarbonImmutable::now($this->days->zone())->offset;
            $cells = [];
            $customers = 0;
            $current = null;
            $months = [];
            foreach ($this->orders->perCustomerMonth((string) config('reporting.currency'), $offset) as $row) {
                if ($current !== null && $current !== $row->customerId) {
                    $cells = $this->addCustomer($cells, $months);
                    $customers++;
                    $months = [];
                }
                $current = $row->customerId;
                $m = $months[$row->month] ?? [0, 0];
                $months[$row->month] = [$m[0] + $row->orders, $m[1] + $row->salesMinor];
            }
            if ($months !== []) {
                $cells = $this->addCustomer($cells, $months);
                $customers++;
            }

            $rows = [];
            ksort($cells);
            foreach ($cells as $cohort => $bySince) {
                ksort($bySince);
                foreach ($bySince as $since => $c) {
                    $rows[] = ['cohort_month' => $cohort, 'months_since' => $since, 'customers' => $c['customers'], 'orders' => $c['orders'], 'sales_minor' => $c['sales'], 'refreshed_at' => $now];
                }
            }

            DB::table('report_cohort_months')->delete();
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('report_cohort_months')->insert($chunk);
            }
            DB::table('report_cohort_status')->where('id', 1)->update(['refreshed_at' => $now, 'cohort_rows' => count($rows), 'customers' => $customers]);   // primary key

            return ['cohort_rows' => count($rows), 'customers' => $customers];
        });
    }

    /**
     * Files one customer under the cohort of their first month, one cell per month they ordered in.
     *
     * @param  array<string, array<int, array{customers: int, orders: int, sales: int}>>  $cells
     * @param  array<string, array{0: int, 1: int}>  $months  month (Y-m) => [orders, sales]
     * @return array<string, array<int, array{customers: int, orders: int, sales: int}>>
     */
    private function addCustomer(array $cells, array $months): array
    {
        ksort($months);
        $first = (string) array_key_first($months);
        foreach ($months as $month => [$orders, $sales]) {
            $since = $this->monthsBetween($first, (string) $month);
            $cell = $cells[$first][$since] ?? ['customers' => 0, 'orders' => 0, 'sales' => 0];
            $cells[$first][$since] = ['customers' => $cell['customers'] + 1, 'orders' => $cell['orders'] + $orders, 'sales' => $cell['sales'] + $sales];
        }

        return $cells;
    }

    /** Whole months from `$first` to `$later` (both Y-m, `$later` not before `$first`). */
    private function monthsBetween(string $first, string $later): int
    {
        return ((int) substr($later, 0, 4) - (int) substr($first, 0, 4)) * 12 + (int) substr($later, 5, 2) - (int) substr($first, 5, 2);
    }
}
