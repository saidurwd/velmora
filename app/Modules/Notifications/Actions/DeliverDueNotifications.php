<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** Every minute: deliver what is due (queued, or failed and its wait is over) and take over what a dead worker abandoned. Safe to run at any time, by anyone, any number of times. */
final class DeliverDueNotifications
{
    public function __construct(private readonly DeliverNotification $deliver, private readonly Clock $clock) {}

    /** @return int how many messages were sent now */
    public function handle(int $limit = 200): int
    {
        $now = $this->clock->now();
        $stale = $now->subMinutes((int) config('notifications.claim_minutes'));
        $ids = DB::table('notification_logs')
            ->where(fn ($q) => $q->where(fn ($d) => $d->whereIn('status', ['queued', 'failed'])->where('next_attempt_at', '<=', $now))->orWhere(fn ($s) => $s->where('status', 'sending')->where('claimed_at', '<', $stale)))
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id');   // index (status, next_attempt_at)

        $sent = 0;
        foreach ($ids as $id) {
            $sent += $this->deliver->handle((int) $id) === 'sent' ? 1 : 0;
        }

        return $sent;
    }
}
