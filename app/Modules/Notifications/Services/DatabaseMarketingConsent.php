<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\MarketingConsent;
use Illuminate\Support\Facades\DB;

final class DatabaseMarketingConsent implements MarketingConsent
{
    public function allows(int $userId, string $channel): bool
    {
        return (bool) DB::table('notification_preferences')->where('user_id', $userId)->where('channel', $channel)->value('marketing_opt_in');   // unique (user_id, channel)
    }
}
