<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\ProcessPaymentEventAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Processes one stored gateway notification. Safe to run again: a notification already dealt with is skipped. */
class ProcessPaymentEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** @return list<int> seconds between tries */
    public function backoff(): array
    {
        return [10, 30, 90, 300, 900];
    }

    public function __construct(public readonly int $eventId) {}

    public function handle(ProcessPaymentEventAction $action): void
    {
        $action->handle($this->eventId);
    }

    public function failed(Throwable $e): void
    {
        DB::table('payment_webhook_events')->where('id', $this->eventId)->whereIn('status', ['received'])->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 250)]);
    }
}
