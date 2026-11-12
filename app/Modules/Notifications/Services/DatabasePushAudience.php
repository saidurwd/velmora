<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\PushAudience;
use Illuminate\Support\Facades\DB;

final class DatabasePushAudience implements PushAudience
{
    public function reaches(int $userId): bool
    {
        return DB::table('devices')->where('user_id', $userId)->exists();   // index user_id
    }
}
