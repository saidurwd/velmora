<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\ResolveShipmentIssue;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Concerns\ActsAsStaff;
use App\Modules\Shipping\Http\Requests\Admin\ResolveIssueRequest;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentIssue;
use App\Modules\Shipping\Services\ShippingHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** What needs a person: unknown courier words, parcels that ran out of attempts, statement differences. Closing one records who and why. */
class IssueController
{
    use ActsAsStaff;

    public function index(Request $request, ShippingHealth $health): View
    {
        $status = in_array($request->query('status'), ['open', 'resolved', 'ignored'], true) ? (string) $request->query('status') : 'open';
        $issues = ShipmentIssue::query()->where('status', $status)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('shipping::admin.issues.index', [
            'alerts' => $health->breached(), 'issues' => $issues, 'status' => $status, 'can' => $request->user('admin')?->can('shipping.manage') === true,
            'parcels' => Shipment::query()->whereIn('id', array_filter($issues->pluck('shipment_id')->all()))->pluck('public_id', 'id'),
        ]);
    }

    public function resolve(ResolveIssueRequest $request, string $issue, ResolveShipmentIssue $resolve): RedirectResponse
    {
        try {
            $resolve->handle($this->actor($request), $issue, (string) $request->validated('outcome'), (string) $request->validated('note'));
        } catch (ShipmentException $e) {
            return back()->withErrors(['issue' => __('shipping::shipments.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('shipping::fulfilment.issues.done'));
    }
}
