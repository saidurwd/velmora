<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The gateway's server-to-server notification. It does the minimum and answers at once: read it, store it exactly as it came
 * (the same notification twice is stored once), and queue the real work. Nothing in it is believed; processing asks the gateway.
 */
final class ReceivePaymentNotification
{
    public function __construct(private readonly GatewayRegistry $gateways, private readonly Clock $clock) {}

    /**
     * @return bool false when it was a repeat of one already stored
     *
     * @throws PaymentException unknown provider, or a body that cannot be read
     */
    public function handle(string $provider, Request $request): bool
    {
        $event = $this->gateways->for($provider)->parseCallback($request);

        $stored = DB::table('payment_webhook_events')->insertOrIgnore([
            'provider' => $provider, 'provider_event_id' => mb_substr($event->providerEventId, 0, 191), 'payload' => json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'status' => 'received', 'received_at' => $this->clock->now(),
        ]);
        if ($stored === 0) {
            return false;
        }

        $id = (int) DB::table('payment_webhook_events')->where('provider', $provider)->where('provider_event_id', mb_substr($event->providerEventId, 0, 191))->value('id');
        ProcessPaymentEvent::dispatch($id)->afterCommit();

        return true;
    }
}
