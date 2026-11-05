<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

/** Daily: a dead message keeps its encrypted address and values only for the retention period (14 days unless the setting says otherwise); then they are erased. The record itself stays. */
final class PurgeNotificationPayloads
{
    public function __construct(private readonly Settings $settings, private readonly Clock $clock) {}

    /** @return int how many payloads were erased */
    public function handle(): int
    {
        $days = max(1, $this->settings->int('notifications.dead_retention_days', (int) config('notifications.dead_retention_days')));

        return DB::table('notification_logs')->where('status', 'dead')->whereNotNull('payload')->where('updated_at', '<', $this->clock->now()->subDays($days))->update(['payload' => null]);   // index (status, next_attempt_at)
    }
}
