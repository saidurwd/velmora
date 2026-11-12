<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use Illuminate\Support\Facades\DB;

final class RemoveDevice
{
    /** @return bool whether a device of THIS person with this id existed (somebody else's is "no such device") */
    public function handle(int $userId, string $publicId): bool
    {
        return DB::table('devices')->where('user_id', $userId)->where('public_id', $publicId)->delete() > 0;
    }
}
