<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Jobs\BuildExportJob;
use App\Modules\Privacy\Models\PrivacyRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A signed-in customer asks for a copy of their data. One at a time (the database refuses a second open request), and one a day. The copy is built in the
 * background; the customer sees it on their privacy page when it is ready. The user comes from the session, never from the request.
 */
final class RequestExport
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly Settings $settings, private readonly CustomerProfiles $profiles) {}

    /** @throws PrivacyException not_a_customer | already_open | too_soon */
    public function handle(int $userId, string $locale): PrivacyRequest
    {
        if ($this->profiles->findByUserId($userId) === null) {
            throw new PrivacyException('not_a_customer');
        }

        $now = $this->clock->now();
        $cooldown = max(1, $this->settings->int('privacy.export_cooldown_hours', (int) config('privacy.export_cooldown_hours', 24)));
        $recent = DB::table('privacy_requests')->where('user_id', $userId)->where('type', 'export')->where('status', '<>', 'failed')->where('requested_at', '>', $now->subHours($cooldown))->exists();   // index (user_id, type, requested_at); a copy that could not be made does not count
        if ($recent) {
            throw new PrivacyException('too_soon');
        }

        try {
            $id = DB::table('privacy_requests')->insertGetId([
                'public_id' => strtolower((string) Str::ulid()), 'user_id' => $userId, 'type' => 'export', 'status' => 'requested', 'open_key' => $userId.':export',
                'locale' => in_array($locale, ['en', 'bn'], true) ? $locale : 'en', 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('already_open');   // two presses at once: the unique open_key lets one through
        }

        $this->audit->record('privacy.export_requested', 'privacy_request', [], [], null, $id);
        DB::afterCommit(fn () => BuildExportJob::dispatch($id));

        return PrivacyRequest::query()->findOrFail($id);
    }
}
