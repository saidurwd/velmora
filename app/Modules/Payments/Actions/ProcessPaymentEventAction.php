<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** Works through one stored notification: finds our transaction by OUR reference, has it verified with the gateway, and records what happened. */
final class ProcessPaymentEventAction
{
    public function __construct(private readonly VerifyPayment $verify, private readonly Clock $clock) {}

    public function handle(int $eventId): void
    {
        $event = PaymentWebhookEvent::query()->find($eventId);
        if ($event === null || $event->status === 'processed' || $event->status === 'ignored') {
            return; // already dealt with
        }

        $reference = isset($event->payload['tran_id']) ? (string) $event->payload['tran_id'] : null;
        $hint = isset($event->payload['val_id']) ? (string) $event->payload['val_id'] : null;
        $tx = $reference === null ? null : PaymentTransaction::query()->where('merchant_ref', $reference)->first();

        if ($tx === null) {
            $this->finish($eventId, null, 'ignored', null, 'unknown_reference'); // not one of ours: nothing to verify, nothing to trust

            return;
        }

        $outcome = $this->verify->handle($tx->merchant_ref, $hint);

        if ($outcome === VerifyOutcome::ProviderError) {
            throw new \RuntimeException('Gateway could not be asked; the notification will be retried.'); // the job retries with a back-off
        }

        $this->finish($eventId, $tx->payment_id, 'processed', $outcome !== VerifyOutcome::NoChange, null);
    }

    private function finish(int $eventId, ?int $paymentId, string $status, ?bool $verified, ?string $error): void
    {
        DB::table('payment_webhook_events')->where('id', $eventId)->update(['payment_id' => $paymentId, 'status' => $status, 'verified' => $verified, 'error' => $error, 'processed_at' => $this->clock->now()]);
    }
}
