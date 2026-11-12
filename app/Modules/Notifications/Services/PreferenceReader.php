<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;

/** What one customer has agreed to receive, and the recent history of their answers. Always for the one user asked about. */
final class PreferenceReader
{
    /** @return array{optIn: array<string, bool>, quietFrom: string|null, quietTo: string|null, history: array<int, array{channel: string, granted: bool, source: string, at: string}>} */
    public function forUser(int $userId): array
    {
        $rows = DB::table('notification_preferences')->where('user_id', $userId)->get();   // unique (user_id, channel)
        $optIn = ['email' => false, 'sms' => false, 'push' => false];
        $from = $to = null;
        foreach ($rows as $row) {
            $optIn[(string) $row->channel] = (bool) $row->marketing_opt_in;
            $from ??= $row->quiet_from === null ? null : substr((string) $row->quiet_from, 0, 5);
            $to ??= $row->quiet_to === null ? null : substr((string) $row->quiet_to, 0, 5);
        }

        $history = DB::table('notification_consent_events')->where('user_id', $userId)->orderByDesc('id')->limit(10)->get()   // index (user_id, id)
            ->map(fn ($e) => ['channel' => (string) $e->channel, 'granted' => (bool) $e->granted, 'source' => (string) $e->source, 'at' => (string) $e->created_at])->all();

        return ['optIn' => $optIn, 'quietFrom' => $from, 'quietTo' => $to, 'history' => $history];
    }
}
