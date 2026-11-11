<?php

declare(strict_types=1);

namespace App\Modules\Payments\Dashboard;

use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Support\Facades\DB;

/** Refunds waiting for a decision, for sending, or with the gateway: exactly what the refund queue lists. */
final class RefundQueueTile implements DashboardTile
{
    public function code(): string
    {
        return 'refund_queue';
    }

    public function label(): string
    {
        return 'payments::dashboard.refund_queue';
    }

    public function permissions(): array
    {
        return ['payments.view'];
    }

    public function figure(): TileFigure
    {
        return new TileFigure(DB::table('refunds')->whereIn('status', ['requested', 'approved', 'processing'])->count(), 'count', 'admin.payments.refunds.index');   // index (status, created_at)
    }
}
