<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Dashboard;

use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Support\Facades\DB;

/** Tickets still being handled (open or answered): what the support queue lists by default. */
final class OpenTicketsTile implements DashboardTile
{
    public function code(): string
    {
        return 'tickets_open';
    }

    public function label(): string
    {
        return 'engagement::dashboard.tickets_open';
    }

    public function permissions(): array
    {
        return ['support.view'];
    }

    public function figure(): TileFigure
    {
        return new TileFigure(DB::table('support_tickets')->whereIn('status', ['open', 'answered'])->count(), 'count', 'admin.support.index', ['status' => 'active']);   // index (status, sla_due_at)
    }
}
