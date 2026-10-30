<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\CallbackEvent;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\InitiationResult;
use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\DTOs\RefundRequest;
use App\Modules\Payments\DTOs\RefundResult;
use App\Modules\Payments\DTOs\RefundStatusResult;
use App\Modules\Payments\DTOs\VerificationRequest;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Exceptions\PaymentException;
use Illuminate\Http\Request;

/**
 * One payment provider. The rest of the system talks only to this; an adapter is written from the provider's saved official
 * documentation (docs/vendor/<provider>/) and declares in `capabilities()` what that documentation shows it can do.
 *
 * Security rule for every adapter: `verify()` and `status()` ask the provider server to server and report the provider's own
 * answer. Nothing read from a browser redirect or a notification body is ever returned as if it were verified.
 */
interface PaymentGateway
{
    public function name(): string;

    public function capabilities(): Capabilities;

    /** Starts a payment at the provider and says where to send the customer. @throws PaymentException */
    public function initiate(PaymentIntent $intent): InitiationResult;

    /** Reads a notification or browser return into our terms. Does not trust or verify it. @throws PaymentException when it cannot be read */
    public function parseCallback(Request $request): CallbackEvent;

    /** Asks the provider (server to server) about a transaction. @throws PaymentException when the provider cannot be reached or answers nonsense */
    public function verify(VerificationRequest $request): VerifiedPayment;

    /** The provider's current view of a transaction, for reconciliation. @throws PaymentException */
    public function status(string $merchantRef): VerifiedPayment;

    /** @throws PaymentException when the provider cannot refund */
    public function refund(RefundRequest $request): RefundResult;

    /** @throws PaymentException */
    public function refundStatus(string $providerRefundId): RefundStatusResult;
}
