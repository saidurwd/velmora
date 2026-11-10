<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Modules\Orders\Contracts\CancellationFigures;
use App\Modules\Orders\Enums\CancelReason;
use App\Modules\Payments\Contracts\RefundReasonFigures;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Platform\DTOs\ReasonFigure;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Services\Ratio;
use App\Modules\Reporting\Services\ReportDays;

/**
 * Why orders were cancelled (by the day they were cancelled) and why money went back (by the day the refund went through), by reason code, most first within each.
 * Those made before codes existed are shown together as "Not recorded". The staff member's own words are never shown here.
 */
final class ReasonsForRefundsAndCancellations implements ReportDefinition
{
    public function __construct(private readonly CancellationFigures $cancellations, private readonly RefundReasonFigures $refunds, private readonly ReportDays $days) {}

    public function code(): string
    {
        return 'refund_cancel_reasons';
    }

    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function columns(): array
    {
        return [new ReportColumn('type'), new ReportColumn('reason'), new ReportColumn('count', 'count'), new ReportColumn('value', 'money', finance: true), new ReportColumn('share', 'percent')];
    }

    public function rows(ReportFilter $filter): iterable
    {
        $from = $this->days->bounds($filter->from)[0];
        $to = $this->days->bounds($filter->to)[1];

        yield from $this->rowsOf('cancellation', $this->cancellations->cancelledBetween($from, $to), fn (string $c): ?string => CancelReason::tryFrom($c)?->label());
        yield from $this->rowsOf('refund', $this->refunds->succeededBetween($from, $to), fn (string $c): ?string => RefundReason::tryFrom($c)?->label());
    }

    /**
     * @param  list<ReasonFigure>  $figures
     * @param  callable(string): ?string  $label
     * @return iterable<array<string, mixed>>
     */
    private function rowsOf(string $type, array $figures, callable $label): iterable
    {
        $currency = (string) config('reporting.currency');
        $byCode = [];
        foreach ($figures as $f) {
            if ($f->currency !== $currency) {
                continue; // another currency is not added to taka
            }
            $key = $f->code ?? '';
            $byCode[$key] = [($byCode[$key][0] ?? 0) + $f->count, ($byCode[$key][1] ?? 0) + $f->amountMinor];
        }
        uksort($byCode, fn (string $a, string $b): int => [$byCode[$b][0], $a === '' ? 1 : 0, $a] <=> [$byCode[$a][0], $b === '' ? 1 : 0, $b]);   // most first; ties by code, with “not recorded” last
        $total = array_sum(array_column($byCode, 0));

        foreach ($byCode as $code => [$count, $amount]) {
            $code = (string) $code;
            yield [
                'type' => __('reporting::catalogue.reason_types.'.$type),
                'reason' => $code === '' ? __('reporting::catalogue.reason_types.not_recorded') : ($label($code) ?? $code),
                'count' => $count, 'value' => $amount, 'share' => Ratio::basisPoints($count, $total),
            ];
        }
    }
}
