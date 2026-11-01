<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Shipping\Actions\DecideReturn;
use App\Modules\Shipping\Actions\InspectReturnItem;
use App\Modules\Shipping\Actions\ReceiveReturn;
use App\Modules\Shipping\Enums\ReturnOutcome;
use App\Modules\Shipping\Enums\ReturnStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Concerns\ActsAsStaff;
use App\Modules\Shipping\Http\Requests\Admin\AuthorizeReturnRequest;
use App\Modules\Shipping\Http\Requests\Admin\InspectReturnRequest;
use App\Modules\Shipping\Http\Requests\Admin\ReasonRequest;
use App\Modules\Shipping\Http\Requests\Admin\ReceiveReturnRequest;
use App\Modules\Shipping\Models\ReturnRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Returns for staff: allow or refuse, count what came back, then look at each item. A refund is only suggested here; it is never made. */
class ReturnController
{
    use ActsAsStaff;

    public function __construct(private readonly OrderDirectory $orders, private readonly OrderFulfilment $fulfilment) {}

    public function index(Request $request): View
    {
        $status = ReturnStatus::tryFrom((string) $request->query('status'));
        $returns = ReturnRequest::query()->when($status !== null, fn ($q) => $q->where('status', $status?->value))->orderByDesc('id')->paginate(25)->withQueryString();

        return view('shipping::admin.returns.index', [
            'returns' => $returns, 'status' => $status, 'statuses' => ReturnStatus::cases(),
            'orders' => $this->orders->references(array_values(array_unique(array_map('intval', $returns->pluck('order_id')->all())))),
        ]);
    }

    public function show(Request $request, string $return): View
    {
        $found = ReturnRequest::query()->where('public_id', $return)->with('items')->firstOrFail();
        $ref = $this->orders->references([$found->order_id])[$found->order_id] ?? null;
        $view = $ref === null ? null : $this->fulfilment->view($ref['public_id']);
        $locale = app()->getLocale();
        $names = [];
        foreach ($view->lines ?? [] as $line) {
            $names[$line->orderItemId] = $line->names[$locale] ?? $line->names[(string) config('app.default_locale')] ?? $line->name;
        }
        $user = $request->user('admin');

        return view('shipping::admin.returns.show', [
            'return' => $found, 'order' => $ref, 'names' => $names, 'outcomes' => array_values(array_filter(ReturnOutcome::cases(), fn (ReturnOutcome $o) => $o->touchesStock())),
            'canDecide' => $user?->can('shipping.manage') === true, 'canHandle' => $user?->can('shipping.create_shipment') === true,
        ]);
    }

    public function authorizeReturn(AuthorizeReturnRequest $request, string $return, DecideReturn $decide): RedirectResponse
    {
        return $this->run(fn () => $decide->authorize($this->actor($request), $return, (string) $request->validated('payer')), 'authorized');
    }

    public function reject(ReasonRequest $request, string $return, DecideReturn $decide): RedirectResponse
    {
        return $this->run(fn () => $decide->reject($this->actor($request), $return, (string) $request->validated('reason')), 'rejected');
    }

    public function cancel(ReasonRequest $request, string $return, DecideReturn $decide): RedirectResponse
    {
        return $this->run(fn () => $decide->cancel($this->actor($request), $return, (string) $request->validated('reason')), 'cancelled');
    }

    public function receive(ReceiveReturnRequest $request, string $return, ReceiveReturn $receive): RedirectResponse
    {
        $received = [];
        foreach ((array) $request->validated('received') as $itemId => $quantity) {
            if ($quantity !== null && $quantity !== '') {
                $received[(int) $itemId] = (int) $quantity;
            }
        }

        return $this->run(fn () => $receive->handle($this->actor($request), $return, $received === [] ? null : $received), 'received');
    }

    public function inspect(InspectReturnRequest $request, string $return, InspectReturnItem $inspect): RedirectResponse
    {
        return $this->run(fn () => $inspect->handle($this->actor($request), $return, (int) $request->validated('line'), (string) $request->validated('outcome'), $request->validated('note')), 'inspected');
    }

    private function run(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (ShipmentException $e) {
            return back()->withErrors(['return' => __('shipping::shipments.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('shipping::fulfilment.returns.done.'.$done));
    }
}
