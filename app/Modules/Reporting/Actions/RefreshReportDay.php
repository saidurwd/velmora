<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Orders\Contracts\SalesFigures;
use App\Modules\Orders\DTOs\OrderFact;
use App\Modules\Payments\Contracts\PaymentFigures;
use App\Modules\Payments\DTOs\PaymentFact;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Reporting\Services\MoneyGroup;
use App\Modules\Reporting\Services\ReportDays;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Works one report day out again from the ledgers and replaces what was stored for it, as a whole. Running it twice gives the same rows; two runs at once take turns
 * (the day's status row is locked), so a day is never half old and half new. It reads Orders and Payments only through their Contracts.
 *
 * Money in is counted by when it was paid (cash on delivery: when it was collected); a refund by when it succeeded. Both are filed under the channel and language of the
 * ORDER they belong to, so a report can be filtered the same way as sales. A refund that belongs to an order that was cancelled is kept apart (`refunded_live_minor` is
 * the rest): the cancelled order was never counted in sales, so taking its refund off net sales as well would count it twice.
 */
final class RefreshReportDay
{
    public function __construct(
        private readonly SalesFigures $sales,
        private readonly PaymentFigures $payments,
        private readonly ReportDays $days,
        private readonly Clock $clock,
    ) {}

    /** @param  'schedule'|'nightly'|'rebuild'  $trigger */
    public function handle(string $day, string $trigger = 'schedule'): void
    {
        if ($day > $this->days->today()) {
            throw new InvalidArgumentException("{$day} has not started yet");
        }
        [$from, $to] = $this->days->bounds($day);
        $now = $this->clock->now();

        Transactions::run(function () use ($day, $trigger, $from, $to, $now): void {
            // One statement creates the day's status row or locks the one that is there (an exclusive lock either way). Whoever works this day out holds it until the rows are
            // replaced, and reads the ledgers only AFTER getting it: a slower run that waited can never write older figures over the newer ones of the run before it.
            // (Insert-then-lock would let several runs each take a shared lock first and deadlock when they all ask for the exclusive one.)
            DB::statement('INSERT INTO report_day_status (`day`, refreshed_at, `trigger`, sales_rows, payment_rows) VALUES (?, ?, ?, 0, 0) ON DUPLICATE KEY UPDATE `day` = `day`', [$day, $now, $trigger]);   // primary key

            $sales = $this->sales->placedBetween($from, $to);
            $captured = $this->payments->capturedBetween($from, $to);
            $refunds = $this->payments->refundedBetween($from, $to);
            $facts = $this->sales->facts(array_values(array_unique([...array_map(fn (PaymentFact $f): int => $f->orderId, $captured), ...array_map(fn (PaymentFact $f): int => $f->orderId, $refunds)])));
            $money = $this->money($captured, $refunds, $facts);

            DB::table('report_daily_sales')->where('day', $day)->delete();           // unique (day, ...)
            DB::table('report_daily_payments')->where('day', $day)->delete();
            foreach ($sales as $s) {
                DB::table('report_daily_sales')->insert([
                    'day' => $day, 'channel' => $s->channel, 'payment_method' => $s->paymentMethod, 'locale' => $s->locale, 'currency' => $s->currency, 'orders' => $s->orders,
                    'gross_minor' => $s->grossMinor, 'discounts_minor' => $s->discountsMinor, 'shipping_minor' => $s->shippingMinor, 'cod_fee_minor' => $s->codFeeMinor, 'tax_minor' => $s->taxMinor,
                    'billed_minor' => $s->billedMinor, 'cancelled_orders' => $s->cancelledOrders, 'cancelled_billed_minor' => $s->cancelledBilledMinor, 'refreshed_at' => $now,
                ]);
            }
            foreach ($money as $group) {
                DB::table('report_daily_payments')->insert(['day' => $day, ...$group->row(), 'refreshed_at' => $now]);
            }
            DB::table('report_day_status')->where('day', $day)->update(['refreshed_at' => $now, 'trigger' => $trigger, 'sales_rows' => count($sales), 'payment_rows' => count($money)]);
        });
    }

    /**
     * @param  list<PaymentFact>  $captured
     * @param  list<PaymentFact>  $refunds
     * @param  array<int, OrderFact>  $facts
     * @return array<string, MoneyGroup>
     */
    private function money(array $captured, array $refunds, array $facts): array
    {
        $groups = [];
        $group = function (PaymentFact $f, ?OrderFact $fact) use (&$groups): MoneyGroup {
            $channel = $fact->channel ?? 'unknown';
            $locale = $fact->locale ?? '??';

            return $groups[implode('|', [$channel, $f->methodCode, $locale, $f->currency])] ??= new MoneyGroup($channel, $f->methodCode, $locale, $f->currency);
        };
        foreach ($captured as $f) {
            $g = $group($f, $facts[$f->orderId] ?? null);
            $g->collectedMinor += $f->amountMinor;
            $g->collectedCount++;
        }
        foreach ($refunds as $f) {
            $fact = $facts[$f->orderId] ?? null;
            $g = $group($f, $fact);
            $g->refundedMinor += $f->amountMinor;
            $g->refundedLiveMinor += $fact !== null && ! $fact->cancelled ? $f->amountMinor : 0;
            $g->refundCount++;
        }

        return $groups;
    }
}
