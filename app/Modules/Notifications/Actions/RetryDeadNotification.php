<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Exceptions\NotificationException;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Staff send a dead message again, with a reason. Only a dead message whose (encrypted) address and values are still kept; one conditional update, so pressing twice retries once. Who may (`notifications.manage`) is decided by the caller. */
final class RetryDeadNotification
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws NotificationException not_found | not_dead | payload_expired | reason_required */
    public function handle(Actor $actor, string $publicId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new NotificationException('reason_required');
        }

        $row = DB::table('notification_logs')->where('public_id', $publicId)->first();   // unique public_id
        if ($row === null) {
            throw new NotificationException('not_found');
        }
        if ($row->status !== 'dead') {
            throw new NotificationException('not_dead');
        }
        if ($row->payload === null) {
            throw new NotificationException('payload_expired');
        }

        $now = $this->clock->now();
        if (DB::table('notification_logs')->where('id', $row->id)->where('status', 'dead')->update(['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => $now, 'last_error' => null, 'updated_at' => $now]) !== 1) {
            throw new NotificationException('not_dead');   // someone else just did
        }
        $this->audit->record('notifications.retried', 'notification_log', [], ['template' => $row->template_code, 'reason' => $reason], $actor, $row->id);
        DB::afterCommit(fn () => DeliverNotificationJob::dispatch((int) $row->id));
    }
}
