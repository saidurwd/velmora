<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Modules\Payments\Actions\ReceivePaymentNotification;
use App\Modules\Payments\Exceptions\PaymentException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The gateway's server-to-server notification (IPN). Always answers quickly; the work is queued. */
class PaymentNotificationController
{
    public function receive(Request $request, ReceivePaymentNotification $receive, string $provider): Response
    {
        try {
            $receive->handle($provider, $request);
        } catch (PaymentException $e) {
            abort($e->reason === 'unknown_provider' ? 404 : 400);
        }

        return response('OK', 200); // a repeat of a stored notification is also "OK": the gateway should stop resending
    }
}
