<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Every hour: how many tickets were not answered in time? When there are any, one error line is written for whoever watches the logs. Returns the number. */
final class CheckSupportHealth
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(): int
    {
        $overdue = DB::table('support_tickets')->where('status', 'open')->whereNull('first_responded_at')->where('sla_due_at', '<', $this->clock->now())->count();   // index (status, sla_due_at)
        if ($overdue > 0) {
            Log::error('support.alert', ['overdue_first_responses' => $overdue]);
        }

        return $overdue;
    }
}
