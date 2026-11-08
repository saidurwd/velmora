<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Orders\Contracts\SalesFigures;
use App\Modules\Pricing\Contracts\CouponFigures;
use App\Modules\Pricing\DTOs\CouponUse;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\Ratio;
use App\Modules\Reporting\Services\ReportDays;

/**
 * What each coupon cost and what it was used on, for uses in the period. Uses on cancelled orders and on orders in another currency are left out. An order that used two coupons
 * counts in full under each, so the goods price column is not meant to be added up across coupons.
 */
final class CouponPerformance implements ReportDefinition
{
    private const PAGE = 500;

    public function __construct(private readonly CouponFigures $coupons, private readonly SalesFigures $sales, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'coupon_performance';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.finance.view'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function columns(): array
    {
        return [
            new ReportColumn('coupon'), new ReportColumn('uses', 'count', finance: true), new ReportColumn('discount', 'money', finance: true), new ReportColumn('average_discount', 'money', finance: true),
            new ReportColumn('gross_sales', 'money', finance: true), new ReportColumn('discount_share', 'percent', finance: true),
        ];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $currency = (string) config('reporting.currency');
        $byCode = [];
        $page = [];
        foreach ($this->coupons->usedBetween($this->days->bounds($filter->from)[0], $this->days->bounds($filter->to)[1]) as $use) {
            $page[] = $use;
            if (count($page) >= self::PAGE) {
                $byCode = $this->tally($byCode, $page, $currency);
                $page = [];
            }
        }
        if ($page !== []) {
            $byCode = $this->tally($byCode, $page, $currency);
        }

        uasort($byCode, fn (array $a, array $b): int => [$b['discount'], $b['uses']] <=> [$a['discount'], $a['uses']]);   // the dearest coupon first
        foreach ($byCode as $code => $f) {
            yield [
                'coupon' => (string) $code, 'uses' => $f['uses'], 'discount' => $f['discount'], 'average_discount' => intdiv(2 * $f['discount'] + $f['uses'], 2 * $f['uses']),
                'gross_sales' => $f['gross'], 'discount_share' => Ratio::basisPoints($f['discount'], $f['gross']),
            ];
        }
    }

    /**
     * Adds one page of uses to the running figures, leaving out cancelled orders and orders in another currency.
     *
     * @param  array<string, array{uses: int, discount: int, gross: int}>  $byCode
     * @param  list<CouponUse>  $page
     * @return array<string, array{uses: int, discount: int, gross: int}>
     */
    private function tally(array $byCode, array $page, string $currency): array
    {
        $totals = $this->sales->totals(array_map(fn (CouponUse $u): int => $u->orderId, $page));
        foreach ($page as $use) {
            $order = $totals[$use->orderId] ?? null;
            if ($order === null || $order->cancelled || $order->currency !== $currency) {
                continue;
            }
            $f = $byCode[$use->code] ?? ['uses' => 0, 'discount' => 0, 'gross' => 0];
            $byCode[$use->code] = ['uses' => $f['uses'] + 1, 'discount' => $f['discount'] + $use->discountMinor, 'gross' => $f['gross'] + $order->grossMinor];
        }

        return $byCode;
    }
}
