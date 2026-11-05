<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Privacy\Exceptions\PrivacyException;
use Illuminate\Support\Facades\DB;

/** The customer changes their mind before staff have decided. Only their own request, only while it is still waiting. */
final class CancelErasureRequest
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws PrivacyException not_found | not_waiting */
    public function handle(int $userId, string $publicId): void
    {
        $row = DB::table('privacy_requests')->where('public_id', $publicId)->where('user_id', $userId)->where('type', 'erasure')->first();   // unique public_id
        if ($row === null) {
            throw new PrivacyException('not_found');
        }
        $now = $this->clock->now();
        // One conditional update: a request that staff approved a moment ago can no longer be cancelled.
        if (DB::table('privacy_requests')->where('id', $row->id)->where('status', 'requested')->update(['status' => 'cancelled', 'open_key' => null, 'completed_at' => $now, 'updated_at' => $now]) !== 1) {
            throw new PrivacyException('not_waiting');
        }
        $this->audit->record('privacy.erasure_cancelled', 'privacy_request', [], [], null, (int) $row->id);
    }
}
