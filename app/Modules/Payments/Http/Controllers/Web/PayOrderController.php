<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Modules\Orders\Contracts\OrderPayables;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Exceptions\PaymentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

/** "Pay now" / "Pay again" on the order page: starts a new attempt and sends the customer to the gateway. Reached only by the signed link. */
class PayOrderController
{
    public function start(OrderPayables $payables, InitiatePayment $initiate, string $order): RedirectResponse
    {
        $request = $payables->payable($order);
        $thanks = fn (): string => URL::temporarySignedRoute('checkout.thanks', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => app()->getLocale(), 'order' => $order]);

        if ($request === null) {
            return redirect()->to($thanks()); // already paid, cancelled, or not an online order: the order page says which
        }

        try {
            return redirect()->away($initiate->handle($request));
        } catch (PaymentException $e) {
            return redirect()->to($thanks())->with('payment_error', $e->reason);
        }
    }
}
