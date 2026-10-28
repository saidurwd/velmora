<?php

declare(strict_types=1);

namespace App\Modules\Platform\Outbox;

use App\Modules\Platform\Contracts\Clock;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Runs $work at most once per (consumer, outbox message), atomically with the "consumed" marker. */
final class IdempotentConsumer
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  Closure(): void  $work */
    public function once(string $consumer, DomainEvent $event, Closure $work): bool
    {
        if ($event->outboxId === null) {
            $work(); // not delivered via the outbox (e.g. a unit test); nothing to dedupe against

            return true;
        }

        try {
            DB::transaction(function () use ($consumer, $event, $work): void {
                DB::table('consumed_events')->insert([
                    'consumer' => $consumer,
                    'outbox_id' => $event->outboxId,
                    'consumed_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
                ]);

                $work();
            });
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
