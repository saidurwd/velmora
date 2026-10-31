<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;

/**
 * "Ask the gateway again" for every attempt of a payment that is not settled. It changes nothing by itself: only the gateway's own answer, through
 * VerifyPayment (amount, currency, reference, status), can make a payment paid. Who may press it (`payments.reconcile`) is decided by the caller.
 */
final class VerifyPaymentManually
{
    public function __construct(private readonly VerifyPayment $verify, private readonly AuditLogger $audit) {}

    /**
     * @return array<string, string> merchant reference => what the gateway's answer led to
     *
     * @throws PaymentException
     */
    public function handle(Actor $actor, string $paymentPublicId): array
    {
        $payment = Payment::query()->where('public_id', $paymentPublicId)->first() ?? throw new PaymentException('refund_not_found');
        if ($payment->provider === RecordCodCollection::PROVIDER) {
            throw new PaymentException('verify_not_applicable'); // cash has no gateway to ask
        }

        $outcomes = [];
        foreach (PaymentTransaction::query()->where('payment_id', $payment->id)->where('status', '!=', 'succeeded')->orderByDesc('attempt')->pluck('merchant_ref') as $ref) {
            $outcomes[(string) $ref] = $this->verify->handle((string) $ref)->value;
        }
        $this->audit->record('payment.manual_verify', $payment, [], ['outcomes' => $outcomes], $actor);

        return $outcomes;
    }
}
