<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\PaymentExceptions;
use App\Modules\Platform\Contracts\Clock;

/**
 * The safety net behind the callbacks: online attempts still unfinished after a few minutes are put to the gateway (its answer, not ours, decides),
 * so a payment whose notification never arrived is still found. A payment that stays open long after its time limit (the expiry job cannot close
 * it, usually because the gateway cannot be reached) is raised to finance once. Asking is safe to repeat: VerifyPayment settles each attempt once.
 */
final class ReconcilePendingPayments
{
    public function __construct(private readonly VerifyPayment $verify, private readonly PaymentExceptions $exceptions, private readonly Clock $clock) {}

    /** @return array{checked: int, paid: int, unreachable: int, long_pending: int} */
    public function handle(int $limit = 50): array
    {
        $now = $this->clock->now();
        $olderThan = $now->subMinutes(max(1, (int) config('payments.reconcile_after_minutes')))->format('Y-m-d H:i:s.u');
        $result = ['checked' => 0, 'paid' => 0, 'unreachable' => 0, 'long_pending' => 0];

        // serves: open attempts older than N minutes (index status, created_at)
        $refs = PaymentTransaction::query()->whereIn('status', ['initiated', 'pending'])->where('created_at', '<=', $olderThan)->orderBy('id')->limit($limit)->pluck('merchant_ref');
        foreach ($refs as $ref) {
            $outcome = $this->verify->handle((string) $ref);
            $result['checked']++;
            $result['paid'] += $outcome === VerifyOutcome::Paid ? 1 : 0;
            $result['unreachable'] += $outcome === VerifyOutcome::ProviderError ? 1 : 0;
        }

        $stuckBefore = $now->subHours(max(1, (int) config('payments.long_pending_hours')))->format('Y-m-d H:i:s.u');
        $stuck = Payment::query()->whereIn('status', [PaymentState::Initiated->value, PaymentState::Pending->value])->where('expires_at', '<=', $stuckBefore)->orderBy('id')->limit($limit)->get();
        foreach ($stuck as $payment) {
            $this->exceptions->raise('long_pending', $payment->id, $payment->order_id, $payment->public_id, ['expired_at' => $payment->expires_at?->toIso8601String(), 'status' => $payment->status->value]);
            $result['long_pending']++;
        }

        return $result;
    }
}
