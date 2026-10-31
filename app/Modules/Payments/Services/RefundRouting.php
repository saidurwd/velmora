<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Models\PaymentTransaction;

/**
 * Gateway or by hand? A refund goes through the gateway only when its adapter declares it can (from the provider's own documentation),
 * the bank transaction id is known, and the amount is something the gateway is known to accept: the whole of the attempt, unless the
 * adapter declares partial refunds. Everything else is a manual refund: a person pays the money back and records the proof.
 */
final class RefundRouting
{
    public function __construct(private readonly GatewayRegistry $gateways, private readonly RefundLedger $ledger) {}

    public function decide(PaymentTransaction $tx, int $amountMinor, bool $forceManual): RefundMethod
    {
        if ($forceManual || $tx->provider_txn_id === null || ! $this->gateways->has($tx->provider)) {
            return RefundMethod::Manual;
        }

        $caps = $this->gateways->for($tx->provider)->capabilities();
        if (! $caps->refund) {
            return RefundMethod::Manual;
        }

        $wholeAttempt = $amountMinor === $tx->amount_minor && $this->ledger->reservedOnTransaction($tx->id) === 0;

        return $wholeAttempt || $caps->partialRefund ? RefundMethod::Gateway : RefundMethod::Manual;
    }
}
