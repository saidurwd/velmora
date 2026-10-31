<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Enums\VerifyOutcome;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Where the gateway sends the customer's browser back to. This is only for the customer's benefit: nothing the browser brings (the
 * path says "success", the form says "VALID") is believed. The real answer is fetched from the gateway server to server, and
 * the customer is then shown the order page, which reads what we recorded.
 */
class PaymentReturnController
{
    public function handle(Request $request, VerifyPayment $verify, OrderDirectory $orders, string $payment, string $outcome): RedirectResponse
    {
        $row = Payment::query()->where('public_id', $payment)->firstOrFail();
        $hint = is_string($request->input('val_id')) ? (string) $request->input('val_id') : null;

        // every attempt of this payment that is still open (the customer may have two tabs), newest first
        $result = VerifyOutcome::NoChange;
        foreach (PaymentTransaction::query()->where('payment_id', $row->id)->whereIn('status', ['initiated', 'pending'])->orderByDesc('attempt')->pluck('merchant_ref') as $ref) {
            $result = $verify->handle((string) $ref, $hint);
            if ($result->isPaid()) {
                break;
            }
        }

        $order = $orders->references([$row->order_id])[$row->order_id] ?? abort(404);
        $url = URL::temporarySignedRoute('checkout.thanks', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => app()->getLocale(), 'order' => $order['public_id']]);

        return redirect()->to($url)->with('payment_outcome', $result === VerifyOutcome::NoChange && $outcome !== 'success' ? 'not_completed' : $result->value);
    }
}
