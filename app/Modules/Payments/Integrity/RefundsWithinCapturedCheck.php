<?php

declare(strict_types=1);

namespace App\Modules\Payments\Integrity;

use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\IntegrityResult;
use Illuminate\Support\Facades\DB;

/** Money given back (or promised back) on a payment never exceeds the money received on it. Failed and rejected refunds do not count. */
final class RefundsWithinCapturedCheck implements IntegrityCheck
{
    public function code(): string
    {
        return 'refunds_within_captured';
    }

    public function run(): IntegrityResult
    {
        $cap = (int) config('ops.integrity.max_problems');

        $rows = DB::select("SELECT p.public_id AS id, p.captured_minor AS captured, SUM(r.amount_minor) AS refunded
              FROM payments p JOIN refunds r ON r.payment_id = p.id AND r.status IN ('requested','approved','processing','succeeded')
             GROUP BY p.id, p.public_id, p.captured_minor HAVING SUM(r.amount_minor) > p.captured_minor LIMIT {$cap}");

        return new IntegrityResult(count($rows), array_values(array_map(fn ($r) => "payment {$r->id}: received {$r->captured}, refunds {$r->refunded} (minor units)", array_slice($rows, 0, (int) config('ops.integrity.sample')))));
    }
}
