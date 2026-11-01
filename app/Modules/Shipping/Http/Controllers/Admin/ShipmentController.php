<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Shipping\Actions\BookShipment;
use App\Modules\Shipping\Actions\CancelShipment;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RecordManualShipmentStatus;
use App\Modules\Shipping\Enums\ShipmentStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Concerns\ActsAsStaff;
use App\Modules\Shipping\Http\Requests\Admin\CreateParcelRequest;
use App\Modules\Shipping\Http\Requests\Admin\ManualStatusRequest;
use App\Modules\Shipping\Http\Requests\Admin\ReasonRequest;
use App\Modules\Shipping\Models\CourierAccount;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShippingHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Parcels for staff: the list, one parcel with its history, and the few things a person does to a parcel. Each button is one Action. */
class ShipmentController
{
    use ActsAsStaff;

    public function __construct(private readonly OrderFulfilment $fulfilment, private readonly OrderDirectory $orders, private readonly ShippingHealth $health) {}

    public function index(Request $request): View
    {
        $status = ShipmentStatus::tryFrom((string) $request->query('status'));
        $courier = preg_match('/^[a-z0-9_]{1,40}$/', (string) $request->query('courier')) === 1 ? (string) $request->query('courier') : null;
        $search = mb_substr(trim((string) $request->query('q')), 0, 80);

        // served by shipments(status, id) and shipments(courier, id); the search is an exact tracking code or consignment id (both indexed or short)
        $parcels = Shipment::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status?->value))
            ->when($courier !== null, fn ($q) => $q->where('courier', $courier))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('tracking_code', $search)->orWhere('courier_consignment_id', $search)->orWhere('public_id', strtolower($search))))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('shipping::admin.shipments.index', [
            'parcels' => $parcels, 'orders' => $this->orders->references(array_values(array_unique(array_map('intval', $parcels->pluck('order_id')->all())))),
            'alerts' => $this->health->breached(), 'statuses' => ShipmentStatus::cases(), 'status' => $status, 'courier' => $courier, 'search' => $search,
            'couriers' => Shipment::query()->distinct()->orderBy('courier')->pluck('courier'),
        ]);
    }

    /** One order's parcels, what is left to send, and the form that makes a parcel. */
    public function order(Request $request, string $order): View
    {
        $view = $this->fulfilment->view($order) ?? abort(404);
        $locale = app()->getLocale();
        $names = [];
        foreach ($view->lines as $line) {
            $names[$line->orderItemId] = $line->names[$locale] ?? $line->names[(string) config('app.default_locale')] ?? $line->name;
        }

        return view('shipping::admin.shipments.order', [
            'order' => $view, 'names' => $names, 'parcels' => Shipment::query()->where('order_id', $view->orderId)->orderBy('id')->get(),
            'accounts' => CourierAccount::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
            'can' => $request->user('admin')?->can('shipping.create_shipment') === true,
        ]);
    }

    public function show(Request $request, string $shipment): View
    {
        $parcel = Shipment::query()->where('public_id', $shipment)->with(['items', 'events'])->firstOrFail();
        $ref = $this->orders->references([$parcel->order_id])[$parcel->order_id] ?? null;
        $view = $ref === null ? null : $this->fulfilment->view($ref['public_id']);
        $locale = app()->getLocale();
        $names = [];
        foreach ($view->lines ?? [] as $line) {
            $names[$line->orderItemId] = $line->names[$locale] ?? $line->names[(string) config('app.default_locale')] ?? $line->name;
        }

        return view('shipping::admin.shipments.show', [
            'parcel' => $parcel, 'order' => $ref, 'names' => $names, 'can' => $request->user('admin')?->can('shipping.create_shipment') === true,
            'manualStatuses' => $parcel->courier === 'manual' ? RecordManualShipmentStatus::STATUSES : [],
        ]);
    }

    public function store(CreateParcelRequest $request, string $order, CreateShipment $create): RedirectResponse
    {
        $lines = [];
        foreach ((array) $request->validated('quantities') as $itemId => $quantity) {
            if ((int) $quantity > 0) {
                $lines[(int) $itemId] = (int) $quantity;
            }
        }

        try {
            $parcel = $create->handle($this->actor($request), $order, $lines, $request->validated('courier') ?: null, $request->validated('note'));
        } catch (ShipmentException $e) {
            return back()->withErrors(['parcel' => __('shipping::shipments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.shipping.shipments.show', $parcel->public_id)->with('status', __('shipping::fulfilment.parcel_made'));
    }

    public function book(Request $request, string $shipment, BookShipment $book): RedirectResponse
    {
        return $this->run($request, fn () => $book->handle($this->actor($request), $shipment), 'booked');
    }

    public function handOver(Request $request, string $shipment, HandOverShipment $handOver): RedirectResponse
    {
        return $this->run($request, fn () => $handOver->handle($this->actor($request), $shipment), 'handed_over');
    }

    public function cancel(ReasonRequest $request, string $shipment, CancelShipment $cancel): RedirectResponse
    {
        return $this->run($request, fn () => $cancel->handle($this->actor($request), $shipment, (string) $request->validated('reason')), 'cancelled');
    }

    public function status(ManualStatusRequest $request, string $shipment, RecordManualShipmentStatus $record): RedirectResponse
    {
        return $this->run($request, fn () => $record->handle($this->actor($request), $shipment, (string) $request->validated('status'), $request->validated('note')), 'status_recorded');
    }

    private function run(Request $request, callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (ShipmentException $e) {
            return back()->withErrors(['parcel' => __('shipping::shipments.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('shipping::fulfilment.done.'.$done));
    }
}
