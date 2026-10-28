<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Retention: OTP challenges are deleted 7 days after expiry (SECURITY_AND_PRIVACY §6). */
class PurgeExpiredOtpChallenges implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        DB::table('otp_challenges')->where('expires_at', '<', now('UTC')->subDays(7)->format('Y-m-d H:i:s.u'))->delete();
    }
}
