<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Hourly: makes failure loud. Dead messages, messages failing again and again, and messages nobody has picked up for a long while are written to the log as one error line, so monitoring shows them (and the staff page lists them). */
final class CheckNotificationHealth
{
    public function __construct(private readonly Clock $clock) {}

    /** @return array{dead: int, failing: int, overdue: int} */
    public function handle(): array
    {
        $now = $this->clock->now();
        $counts = [
            'dead' => DB::table('notification_logs')->where('status', 'dead')->count(),
            'failing' => DB::table('notification_logs')->where('status', 'failed')->where('attempts', '>=', 3)->count(),
            'overdue' => DB::table('notification_logs')->whereIn('status', ['queued', 'failed'])->where('next_attempt_at', '<', $now->subMinutes(30))->count(),
        ];
        if (array_sum($counts) > 0) {
            Log::error('notifications.alert', $counts);
        }

        return $counts;
    }
}
