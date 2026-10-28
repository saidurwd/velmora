<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Listeners;

use App\Modules\Platform\Events\LocaleChosen;
use Illuminate\Support\Facades\DB;

/** Remembers the language a signed-in person picked, so it follows them across devices and sessions. */
final class SaveUserLocale
{
    public function handle(LocaleChosen $event): void
    {
        DB::table('users')->where('id', $event->userId)->where('locale', '!=', $event->locale)->update(['locale' => $event->locale, 'updated_at' => now('UTC')]);
    }
}
