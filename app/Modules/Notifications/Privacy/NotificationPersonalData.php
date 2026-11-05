<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Privacy;

use App\Modules\Notifications\Services\PreferenceReader;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * What the customer agreed to receive, and a list of the messages the shop tried to send them (what kind, when, whether it arrived; never the words or the address).
 * On erasure their preferences go, and any message still waiting for them is dropped with its stored address and values.
 */
final class NotificationPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly PreferenceReader $preferences, private readonly Clock $clock) {}

    public function section(): string
    {
        return 'messages';
    }

    public function export(int $userId): array
    {
        $prefs = $this->preferences->forUser($userId);
        $consent = DB::table('notification_consent_events')->where('user_id', $userId)->orderBy('id')->get()   // index (user_id, id)
            ->map(fn ($e) => ['channel' => $e->channel, 'granted' => (bool) $e->granted, 'source' => $e->source, 'at' => $e->created_at])->all();
        $sent = DB::table('notification_logs')->where('user_id', $userId)->orderBy('id')->limit(2000)->get(['template_code', 'channel', 'status', 'created_at'])   // index (user_id, created_at)
            ->map(fn ($m) => ['message' => $m->template_code, 'channel' => $m->channel, 'status' => $m->status, 'at' => $m->created_at])->all();

        return ['marketing' => $prefs['optIn'], 'quiet_hours' => ['from' => $prefs['quietFrom'], 'to' => $prefs['quietTo']], 'consent_history' => $consent, 'messages' => $sent];
    }

    public function blockers(int $userId): array
    {
        return [];
    }

    public function erase(int $userId): void
    {
        DB::table('notification_preferences')->where('user_id', $userId)->delete();
        $now = $this->clock->now();
        // A message not yet delivered is dropped, together with the address and values kept for it; delivered ones already have none.
        DB::table('notification_logs')->where('user_id', $userId)->whereIn('status', ['queued', 'sending', 'failed', 'dead'])
            ->update(['status' => 'skipped', 'skip_reason' => 'erased', 'payload' => null, 'next_attempt_at' => null, 'updated_at' => $now]);
        DB::table('notification_logs')->where('user_id', $userId)->whereNotNull('payload')->update(['payload' => null, 'updated_at' => $now]);
    }
}
