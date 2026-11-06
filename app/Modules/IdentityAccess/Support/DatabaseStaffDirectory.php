<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Support;

use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;

final class DatabaseStaffDirectory implements StaffDirectory
{
    public function names(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));

        return $ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all();
    }

    public function withPermission(string $permission): array
    {
        $out = [];
        foreach (User::query()->where('account_type', 'staff')->where('status', 'active')->orderBy('name')->get(['id', 'name']) as $user) {   // a handful of staff
            if ($user->hasPermissionTo($permission, 'admin')) {
                $out[(int) $user->id] = (string) $user->name;
            }
        }

        return $out;
    }
}
