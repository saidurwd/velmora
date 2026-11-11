<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Dashboard;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Contracts\DashboardTile;
use App\Modules\Platform\DTOs\TileFigure;
use Illuminate\Support\Facades\DB;

/** Open tickets with no first answer whose time is up: exactly the support queue's "late" filter. */
final class LateTicketsTile implements DashboardTile
{
    public function __construct(private readonly Clock $clock) {}

    public function code(): string
    {
        return 'tickets_late';
    }

    public function label(): string
    {
        return 'engagement::dashboard.tickets_late';
    }

    public function permissions(): array
    {
        return ['support.view'];
    }

    public function figure(): TileFigure
    {
        $n = DB::table('support_tickets')->where('status', 'open')->whereNull('first_responded_at')->where('sla_due_at', '<', $this->clock->now())->count();   // index (status, sla_due_at)

        return new TileFigure($n, 'count', 'admin.support.index', ['overdue' => '1']);
    }
}
