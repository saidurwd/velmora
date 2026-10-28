<?php

declare(strict_types=1);

namespace App\Modules\Platform\Outbox;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Jobs\DispatchOutboxMessages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional outbox writer. Call inside the same DB transaction as the state change, so the event is
 * committed atomically with it; delivery happens afterwards and survives a crash.
 */
final class Outbox
{
    public function __construct(private readonly Clock $clock) {}

    public function record(DomainEvent $event): string
    {
        $publicId = strtolower((string) Str::ulid());
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        DB::table('outbox_messages')->insert([
            'public_id' => $publicId,
            'event_name' => $event::eventName(),
            'payload' => json_encode($event->toPayload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        // Nudge the dispatcher once the surrounding transaction commits; the scheduler is the safety net.
        DB::afterCommit(fn () => DispatchOutboxMessages::dispatch());

        return $publicId;
    }
}
