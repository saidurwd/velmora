<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Support;

use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use Illuminate\Support\Facades\DB;

final class DatabaseStaffDirectory implements StaffDirectory
{
    public function names(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));

        return $ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all();
    }
}
