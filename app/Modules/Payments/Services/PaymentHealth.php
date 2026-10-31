<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The signals that tell a person something is wrong with money, read from our own records. Each has a plain threshold in config/payments.php.
 * `breached` is what the scheduled check logs (once per hour per signal) and what the finance screens show as a banner.
 */
final class PaymentHealth
{
    public const SIGNALS = ['callback_failure_rate', 'pending_too_long', 'amount_mismatches', 'refund_failures', 'forged_notifications'];

    public function __construct(private readonly Clock $clock) {}

    /** @return array<string, array{value: int, threshold: int, breached: bool}> */
    public function snapshot(): array
    {
        $now = $this->clock->now();
        $window = $now->subMinutes((int) config('payments.alerts.window_minutes'))->format('Y-m-d H:i:s.u');
        $day = $now->subDay()->format('Y-m-d H:i:s.u');
        $cfg = (array) config('payments.alerts');

        // notifications the gateway sent in the window (index status, received_at)
        $received = DB::table('payment_webhook_events')->where('received_at', '>=', $window)->count();
        $errors = DB::table('payment_webhook_events')->where('received_at', '>=', $window)->where('status', 'error')->count();
        $failurePercent = $received === 0 ? 0 : intdiv($errors * 100, $received);

        $signals = [
            // percent of the gateway's notifications we could not process (only judged once there are enough of them to mean something)
            'callback_failure_rate' => [$received >= (int) $cfg['min_notifications'] ? $failurePercent : 0, (int) $cfg['callback_failure_percent']],
            // payments still open this long after their time limit: the expiry job should have closed them
            'pending_too_long' => [DB::table('payments')->whereIn('status', ['initiated', 'pending'])->where('expires_at', '<=', $now->subMinutes((int) $cfg['pending_grace_minutes'])->format('Y-m-d H:i:s.u'))->count(), 1],
            // money that disagrees with what the gateway reports, still open
            'amount_mismatches' => [DB::table('reconciliation_exceptions')->where('status', 'open')->whereIn('type', ['amount_mismatch', 'currency_mismatch'])->count(), 1],
            // refunds the gateway refused in the last day, and refunds stuck for days
            'refund_failures' => [DB::table('refunds')->where('status', 'failed')->where('updated_at', '>=', $day)->count() + DB::table('reconciliation_exceptions')->where('status', 'open')->where('type', 'refund_stuck')->count(), 1],
            // notifications the gateway's own answer said were not genuine
            'forged_notifications' => [DB::table('payment_webhook_events')->where('received_at', '>=', $window)->where('verified', false)->count(), (int) $cfg['forged_threshold']],
        ];

        $out = [];
        foreach ($signals as $name => [$value, $threshold]) {
            $out[$name] = ['value' => (int) $value, 'threshold' => $threshold, 'breached' => $value >= $threshold];
        }

        return $out;
    }

    /** @return list<string> the names of the signals that are breached now */
    public function breached(): array
    {
        return array_keys(array_filter($this->snapshot(), fn (array $s): bool => $s['breached']));
    }
}
