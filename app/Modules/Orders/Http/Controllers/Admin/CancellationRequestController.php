<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\DecideCancellationRequest;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Http\Requests\CancellationNoteRequest;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customers' requests to cancel. Looking and answering both need orders.cancel (set on the routes). Approving changes no order and moves no money. */
class CancellationRequestController
{
    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name);
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', 'in:requested,approved,rejected,withdrawn']]);
        $status = ($filters['status'] ?? '') === '' ? 'requested' : $filters['status'];
        $page = DB::table('order_cancellation_requests as r')->join('orders as o', 'o.id', '=', 'r.order_id')->where('r.status', $status)->orderBy('r.id')   // index (status, id)
            ->select(['r.public_id', 'r.status', 'r.note', 'r.decision_note', 'r.created_at', 'o.order_number', 'o.public_id as order_public_id', 'o.status as order_status', 'o.payment_status', 'o.grand_total_minor', 'o.currency'])->paginate(25)->withQueryString();

        return view('orders::admin.cancellation-requests', ['page' => $page, 'status' => $status]);
    }

    public function approve(CancellationNoteRequest $request, DecideCancellationRequest $decide, string $cancellation): RedirectResponse
    {
        try {
            $decide->approve($this->actor($request), $cancellation, $request->validated('note'));
        } catch (OrderException $e) {
            return back()->withErrors(['request' => __('orders::cancel-requests.errors.'.$e->reason)]);
        }

        return back()->with('status', __('orders::cancel-requests.approved_status'));
    }

    public function reject(CancellationNoteRequest $request, DecideCancellationRequest $decide, string $cancellation): RedirectResponse
    {
        try {
            $decide->reject($this->actor($request), $cancellation, (string) $request->validated('note'));
        } catch (OrderException $e) {
            return back()->withErrors(['request' => __('orders::cancel-requests.errors.'.$e->reason)]);
        }

        return back()->with('status', __('orders::cancel-requests.rejected_status'));
    }
}
