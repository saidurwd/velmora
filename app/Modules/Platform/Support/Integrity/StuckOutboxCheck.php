<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support\Integrity;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\IntegrityResult;
use Illuminate\Support\Facades\DB;

/** Messages the system promised to deliver (the outbox): none should wait longer than a few minutes, and none should have given up. */
final class StuckOutboxCheck implements IntegrityCheck
{
    public function __construct(private readonly Clock $clock) {}

    public function code(): string
    {
        return 'outbox_not_stuck';
    }

    public function run(): IntegrityResult
    {
        $cap = (int) config('ops.integrity.max_problems');
        $sample = (int) config('ops.integrity.sample');
        $cutoff = $this->clock->now()->subMinutes((int) config('ops.integrity.stuck_outbox_minutes'))->format('Y-m-d H:i:s.u');

        $stuck = DB::table('outbox_messages')->where('status', 'pending')->where('available_at', '<', $cutoff);   // index (status, available_at)
        $gaveUp = DB::table('outbox_messages')->where('status', 'failed');                                          // index (status, available_at)

        $stuckCount = min($cap, (clone $stuck)->count());   // counts up to the cap
        $gaveUpCount = min($cap, (clone $gaveUp)->count());
        $samples = array_merge(
            $stuck->orderBy('available_at')->limit($sample)->pluck('public_id')->map(fn ($id) => (string) $id)->all(),
            $gaveUp->orderBy('available_at')->limit($sample)->pluck('public_id')->map(fn ($id) => (string) $id)->all(),
        );

        return new IntegrityResult($stuckCount + $gaveUpCount, array_values(array_slice($samples, 0, $sample)), "stuck {$stuckCount}, given up {$gaveUpCount}");
    }
}
