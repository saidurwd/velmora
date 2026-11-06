<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Web;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\RequestReturn;
use App\Modules\Shipping\Actions\WithdrawOwnReturn;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Requests\Web\RequestReturnForm;
use App\Modules\Shipping\Services\ReturnPortal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A signed-in customer sends goods back: asks, follows, and withdraws. The customer is always the one in the session; another customer's order or return is "not found". */
class ReturnPortalController
{
    private function actor(Request $request): Actor
    {
        $user = $request->user('web');
        abort_if($user === null, 403);

        return new Actor(ActorType::Customer, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name);
    }

    public function create(Request $request, ReturnPortal $portal, string $order): View
    {
        $lines = $portal->lines((int) $this->actor($request)->id, $order, app()->getLocale());
        abort_if($lines === null, 404);

        return view('shipping::account.return-form', ['order' => $order, 'lines' => $lines, 'reasons' => RequestReturn::REASONS]);
    }

    public function store(RequestReturnForm $request, ReturnPortal $portal, RequestReturn $return, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $items = [];
        foreach ($request->validated('lines') as $position => $quantity) {
            $item = $portal->itemAt((int) $actor->id, $order, (int) $position);
            abort_if($item === null, 404);   // not this customer's order, or no such line
            $items[$item] = (int) $quantity;
        }

        try {
            $return->handle($actor, $order, $items, (string) $request->validated('reason_code'), $request->validated('note'));
        } catch (ShipmentException $e) {
            abort_if($e->reason === 'order_not_found', 404);

            return back()->withInput()->withErrors(['return' => __('shipping::shipments.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('account.returns')->with('status', __('shipping::returns.sent_status'));
    }

    public function index(Request $request, ReturnPortal $portal): View
    {
        return view('shipping::account.returns', ['returns' => $portal->mine((int) $this->actor($request)->id, app()->getLocale())]);
    }

    public function withdraw(Request $request, WithdrawOwnReturn $withdraw, string $return): RedirectResponse
    {
        try {
            $withdraw->handle($this->actor($request), $return);
        } catch (ShipmentException $e) {
            abort_if($e->reason === 'return_not_found', 404);

            return back()->withErrors(['return' => __('shipping::shipments.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('shipping::returns.withdrawn_status'));
    }
}
