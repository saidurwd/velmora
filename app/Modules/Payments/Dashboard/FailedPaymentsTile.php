<?php

declare(strict_types=1);

namespace App\Modules\Payments\Dashboard;

use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Support\Facades\DB;

final class FailedPaymentsTile implements DashboardTile
{
    public function code(): string
    {
        return 'payments_failed';
    }

    public function label(): string
    {
        return 'payments::dashboard.payments_failed';
    }

    public function permissions(): array
    {
        return ['payments.view'];
    }

    public function figure(): TileFigure
    {
        return new TileFigure(DB::table('payments')->where('status', 'failed')->count(), 'count', 'admin.payments.index', ['status' => 'failed']);   // index (status, created_at)
    }
}
