<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Models\PrivacyRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A signed-in customer asks for their account to be erased. Nothing is erased until staff approve. One open request at a time (the database refuses a second). */
final class RequestErasure
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly CustomerProfiles $profiles) {}

    /** @throws PrivacyException not_a_customer | already_open */
    public function handle(int $userId, ?string $reason, string $locale): PrivacyRequest
    {
        if ($this->profiles->findByUserId($userId) === null) {
            throw new PrivacyException('not_a_customer');
        }

        $now = $this->clock->now();
        try {
            $id = DB::table('privacy_requests')->insertGetId([
                'public_id' => strtolower((string) Str::ulid()), 'user_id' => $userId, 'type' => 'erasure', 'status' => 'requested', 'open_key' => $userId.':erasure',
                'locale' => in_array($locale, ['en', 'bn'], true) ? $locale : 'en', 'reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500),
                'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new PrivacyException('already_open');
        }
        $this->audit->record('privacy.erasure_requested', 'privacy_request', [], [], null, $id);

        return PrivacyRequest::query()->findOrFail($id);
    }
}
