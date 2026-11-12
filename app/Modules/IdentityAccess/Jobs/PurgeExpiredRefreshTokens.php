<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Retention: refresh-token rows are kept after use or revocation so a stolen copy is still recognised, but a token past its expiry can no longer
 * be used by anyone, so its row (used, revoked or never used) is deleted 7 days after it expired.
 */
class PurgeExpiredRefreshTokens implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        DB::table('refresh_tokens')->where('expires_at', '<', now('UTC')->subDays(7)->format('Y-m-d H:i:s.u'))->delete();
    }
}
