<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentFigures;
use App\Modules\Payments\DTOs\PaymentFact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabasePaymentFigures implements PaymentFigures
{
    public function capturedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Index payments (paid_at): one day of payments.
        $rows = DB::table('payments')->where('status', 'paid')->where('captured_minor', '>', 0)
            ->where('paid_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('paid_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->orderBy('id')->get(['order_id', 'method_code', 'currency', 'captured_minor']);

        return array_values($rows->map(fn ($r): PaymentFact => new PaymentFact((int) $r->order_id, (string) $r->method_code, (string) $r->currency, (int) $r->captured_minor))->all());
    }

    public function refundedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Index refunds (status, updated_at): a refund does not change once it has succeeded, so updated_at is when it succeeded.
        $rows = DB::table('refunds as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->where('r.status', 'succeeded')
            ->where('r.updated_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))->where('r.updated_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->orderBy('r.id')->get(['r.order_id', 'p.method_code', 'r.currency', 'r.amount_minor']);

        return array_values($rows->map(fn ($r): PaymentFact => new PaymentFact((int) $r->order_id, (string) $r->method_code, (string) $r->currency, (int) $r->amount_minor))->all());
    }
}
