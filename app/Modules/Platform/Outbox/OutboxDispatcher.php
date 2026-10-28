<?php

declare(strict_types=1);

namespace App\Modules\Platform\Outbox;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers due outbox messages at-least-once. Rows are claimed with FOR UPDATE SKIP LOCKED so parallel workers
 * never take the same message. Consumers are idempotent (IdempotentConsumer), giving exactly-once effect.
 */
final class OutboxDispatcher
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly OutboxRegistry $registry,
        private readonly Clock $clock,
    ) {}

    public function dispatchDue(?int $limit = null): int
    {
        $limit ??= (int) config('velmora.outbox.batch_size', 50);
        $delivered = 0;

        DB::transaction(function () use ($limit, &$delivered): void {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');

            $messages = DB::table('outbox_messages')
                ->where('status', 'pending')
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            foreach ($messages as $message) {
                try {
                    $class = $this->registry->resolve($message->event_name)
                        ?? throw new \RuntimeException("Unknown outbox event [{$message->event_name}].");

                    /** @var array<string, mixed> $payload */
                    $payload = json_decode((string) $message->payload, true, 512, JSON_THROW_ON_ERROR);
                    $event = $class::fromPayload($payload);
                    $event->outboxId = (int) $message->id;

                    $this->events->dispatch($event);

                    DB::table('outbox_messages')->where('id', $message->id)->update([
                        'status' => 'done',
                        'attempts' => $message->attempts + 1,
                        'processed_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
                        'last_error' => null,
                    ]);
                    $delivered++;
                } catch (Throwable $e) {
                    /** @var object{id: int|string, attempts: int} $message */
                    $this->recordFailure($message, $e);
                }
            }
        });

        return $delivered;
    }

    /** @param  object{id: int|string, attempts: int}  $message */
    private function recordFailure(object $message, Throwable $e): void
    {
        $attempts = $message->attempts + 1;
        $max = (int) config('velmora.outbox.max_attempts', 10);
        $backoff = (int) config('velmora.outbox.base_backoff_seconds', 10) * (2 ** min($attempts, 10));

        DB::table('outbox_messages')->where('id', $message->id)->update([
            'status' => $attempts >= $max ? 'failed' : 'pending',
            'attempts' => $attempts,
            'available_at' => $this->clock->now()->addSeconds($backoff)->format('Y-m-d H:i:s.u'),
            'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000),
        ]);
    }
}
