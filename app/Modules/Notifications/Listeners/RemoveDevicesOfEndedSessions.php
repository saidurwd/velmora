<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\IdentityAccess\Events\ApiSessionsEnded;
use Illuminate\Support\Facades\DB;

/** A sign-in that ended takes its phone with it: nothing is pushed to a signed-out device. */
final class RemoveDevicesOfEndedSessions
{
    public function handle(ApiSessionsEnded $event): void
    {
        $devices = DB::table('devices')->where('user_id', $event->userId);

        if ($event->familyIds !== null) {
            $devices->whereIn('family_id', $event->familyIds);
        }

        $devices->delete();
    }
}
