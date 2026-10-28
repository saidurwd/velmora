<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * ADR-014: write transactions on money/stock/orders run READ COMMITTED (InnoDB defaults to REPEATABLE READ,
 * whose gap locks cause deadlocks) and retry a bounded number of times on deadlock / lock-wait.
 */
final class Transactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(Closure $work, int $attempts = 3): mixed
    {
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction($work, max(1, $attempts));
    }
}
