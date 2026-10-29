<?php

declare(strict_types=1);

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Console\Command;
use Throwable;

/** Concurrency-test entry point: each OS process does ONE thing to ONE order and prints a JSON line. Never in production. */
class OrderProbeCommand extends Command
{
    protected $signature = 'orders:probe {action : status|payment|fulfillment|number} {--order=0} {--to=} {--reason=} {--start-at=}';

    protected $description = 'Move an order once, or draw an order number, and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    /**
     * @param  callable(): mixed  $move
     * @return array{ok: true}
     */
    private function did(callable $move): array
    {
        $move();

        return ['ok' => true];
    }

    public function handle(TransitionOrder $transition, OrderNumbers $numbers, Clock $clock): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        $actor = Actor::system('probe');
        $id = (int) $this->option('order');
        $to = (string) $this->option('to');
        $reason = $this->option('reason') ?: null;

        try {
            $result = match ((string) $this->argument('action')) {
                'status' => $this->did(fn () => $transition->status($actor, $id, OrderStatus::from($to), $reason)),
                'payment' => $this->did(fn () => $transition->payment($actor, $id, PaymentStatus::from($to), $reason)),
                'fulfillment' => $this->did(fn () => $transition->fulfillment($actor, $id, FulfillmentStatus::from($to), $reason)),
                default => ['ok' => true, 'number' => $numbers->next($clock->now())],
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (OrderException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
