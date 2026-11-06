<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Web;

use App\Modules\Orders\Actions\RequestOrderCancellation;
use App\Modules\Orders\Actions\WithdrawCancellationRequest;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Http\Requests\CancellationNoteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A signed-in customer asks the shop to cancel their own order, or takes the request back. It only asks. Another customer's order or request is "not found". */
class CancellationRequestController
{
    public function store(CancellationNoteRequest $request, RequestOrderCancellation $ask, string $order): RedirectResponse
    {
        try {
            $ask->handle((int) $request->user('web')?->getAuthIdentifier(), $order, $request->validated('note'));
        } catch (OrderException $e) {
            abort_if($e->reason === 'order_not_found', 404);

            return redirect()->route('account.orders.show', $order)->withErrors(['ask' => __('orders::cancel-requests.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('account.orders.show', $order)->with('status', __('orders::cancel-requests.asked_status'));
    }

    public function withdraw(Request $request, WithdrawCancellationRequest $withdraw, string $order, string $cancellation): RedirectResponse
    {
        try {
            $withdraw->handle((int) $request->user('web')?->getAuthIdentifier(), $cancellation);
        } catch (OrderException $e) {
            abort_if($e->reason === 'order_not_found', 404);

            return redirect()->route('account.orders.show', $order)->withErrors(['ask' => __('orders::cancel-requests.errors.'.$e->reason)]);
        }

        return redirect()->route('account.orders.show', $order)->with('status', __('orders::cancel-requests.withdrawn_status'));
    }
}
