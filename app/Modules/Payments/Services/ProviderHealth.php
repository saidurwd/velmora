<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** How each online provider is doing, from our own records (no call to the provider): set up or not, test or live, last money in, last notification, trouble. */
final class ProviderHealth
{
    public function __construct(private readonly GatewayRegistry $gateways, private readonly Clock $clock) {}

    /** @return list<array{provider: string, methods: list<string>, configured: bool, capabilities: ?Capabilities, last_paid_at: ?string, last_notification_at: ?string, open_attempts: int, notification_errors_24h: int, unprocessed_notifications: int, open_exceptions: int}> */
    public function report(): array
    {
        $now = $this->clock->now();
        $out = [];
        $providers = PaymentMethod::query()->where('type', '!=', 'cod')->orderBy('sort_order')->get()->groupBy('provider');

        foreach ($providers as $provider => $methods) {
            $provider = (string) $provider;
            $configured = $this->gateways->has($provider);
            $paths = DB::table('payment_transactions')->where('provider', $provider);

            $out[] = [
                'provider' => $provider,
                'methods' => array_values($methods->pluck('code')->map(fn ($c): string => (string) $c)->all()),
                'configured' => $configured,
                'capabilities' => $configured ? $this->gateways->for($provider)->capabilities() : null,
                'last_paid_at' => $this->stamp((clone $paths)->where('status', 'succeeded')->max('verified_at')),
                'last_notification_at' => $this->stamp(DB::table('payment_webhook_events')->where('provider', $provider)->max('received_at')),
                'open_attempts' => (int) (clone $paths)->whereIn('status', ['initiated', 'pending'])->count(),
                'notification_errors_24h' => DB::table('payment_webhook_events')->where('provider', $provider)->where('status', 'error')->where('received_at', '>=', $now->subDay()->format('Y-m-d H:i:s.u'))->count(),
                'unprocessed_notifications' => DB::table('payment_webhook_events')->where('provider', $provider)->where('status', 'received')->where('received_at', '<=', $now->subMinutes(10)->format('Y-m-d H:i:s.u'))->count(),
                'open_exceptions' => DB::table('reconciliation_exceptions as e')->join('payments as p', 'p.id', '=', 'e.payment_id')->where('e.status', 'open')->where('p.provider', $provider)->count(),
            ];
        }

        return $out;
    }

    private function stamp(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
