<?php

declare(strict_types=1);

namespace App\Modules\Payments\Dashboard;

use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Support\Facades\DB;

/** Payments a person must look at (money turned up in a way that needs a decision). Links to the payments list with the same filter. */
final class PaymentsNeedReviewTile implements DashboardTile
{
    public function code(): string
    {
        return 'payments_need_review';
    }

    public function label(): string
    {
        return 'payments::dashboard.payments_need_review';
    }

    public function permissions(): array
    {
        return ['payments.view'];
    }

    public function figure(): TileFigure
    {
        return new TileFigure(DB::table('payments')->where('status', 'needs_review')->count(), 'count', 'admin.payments.index', ['status' => 'needs_review']);   // index (status, created_at)
    }
}
