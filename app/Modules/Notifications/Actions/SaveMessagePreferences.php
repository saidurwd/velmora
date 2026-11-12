<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A customer says whether they want marketing messages on each channel, and the hours (Dhaka time) when they should not be disturbed. The current answer is kept in
 * `notification_preferences`; each CHANGE of a yes or no is also written to the append-only `notification_consent_events` (what was agreed, when, from where).
 * Saving the same answer again records nothing new. Messages about the customer's own orders are not affected: they always go.
 */
final class SaveMessagePreferences
{
    public const CHANNELS = ['email', 'sms', 'push'];

    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, bool>  $optIn  channel => yes/no; a channel left out is not touched
     * @param  string|null  $quietFrom  "HH:MM" Dhaka time, or null for none (both or neither of from/to)
     */
    public function handle(int $userId, array $optIn, ?string $quietFrom, ?string $quietTo, string $source = 'account'): void
    {
        if (($quietFrom === null) !== ($quietTo === null) || ($quietFrom !== null && $quietFrom === $quietTo)) {
            throw new InvalidArgumentException('quiet hours need a start and a different end');
        }
        foreach (array_keys($optIn) as $channel) {
            if (! in_array($channel, self::CHANNELS, true)) {
                throw new InvalidArgumentException("unknown channel {$channel}");
            }
        }

        Transactions::run(function () use ($userId, $optIn, $quietFrom, $quietTo, $source): void {
            $now = $this->clock->now();
            foreach (self::CHANNELS as $channel) {
                if (! array_key_exists($channel, $optIn) && ! DB::table('notification_preferences')->where('user_id', $userId)->where('channel', $channel)->exists()) {
                    continue;   // a channel the customer has never answered, and is not answering now, gets no row (a row means "they have been asked")
                }
                // The row is made once (the unique key decides), then locked, so two saves at once change it one after the other and each real change is recorded once.
                DB::table('notification_preferences')->insertOrIgnore(['user_id' => $userId, 'channel' => $channel, 'marketing_opt_in' => false, 'created_at' => $now, 'updated_at' => $now]);
                $row = DB::table('notification_preferences')->where('user_id', $userId)->where('channel', $channel)->lockForUpdate()->first();   // unique (user_id, channel)
                if ($row === null) {
                    continue;
                }
                $changes = ['quiet_from' => $quietFrom, 'quiet_to' => $quietTo, 'updated_at' => $now];
                $before = (bool) $row->marketing_opt_in;
                if (array_key_exists($channel, $optIn)) {
                    if ($optIn[$channel] !== $before) {
                        $changes += ['marketing_opt_in' => $optIn[$channel], 'opted_at' => $now, 'source' => $source];
                        DB::table('notification_consent_events')->insert(['user_id' => $userId, 'channel' => $channel, 'granted' => $optIn[$channel], 'source' => $source, 'created_at' => $now]);
                        $this->audit->record('notifications.consent_changed', 'user', ['marketing' => $before], ['marketing' => $optIn[$channel], 'channel' => $channel, 'source' => $source], null, $userId);
                    } elseif ($row->opted_at === null) {
                        $changes += ['opted_at' => $now, 'source' => $source];   // the first answer, a "no": noted on the current row, not worth a history entry
                    }
                }
                DB::table('notification_preferences')->where('id', $row->id)->update($changes);
            }
        });
    }
}
