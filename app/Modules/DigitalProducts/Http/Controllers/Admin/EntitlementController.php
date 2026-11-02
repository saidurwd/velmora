<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\Actions\AdjustEntitlement;
use App\Modules\DigitalProducts\Actions\RevokeEntitlements;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Http\Concerns\ActsAsStaff;
use App\Modules\DigitalProducts\Http\Requests\AdjustEntitlementRequest;
use App\Modules\DigitalProducts\Http\Requests\RevokeEntitlementRequest;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Services\RefundPolicies;
use App\Modules\DigitalProducts\Services\RefundVerdict;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** What a customer has bought to download, found by order number: their time, downloads and keys, what the refund policy says, and the few things staff may do. */
class EntitlementController
{
    use ActsAsStaff;

    public function index(Request $request, OrderDirectory $orders, VariantDirectory $variants, RefundPolicies $policies, RefundVerdict $verdict, Clock $clock): View
    {
        $number = mb_substr(trim((string) $request->query('order', '')), 0, 20);
        $order = $number === '' ? null : $orders->findByNumber($number);
        $rows = [];
        if ($order !== null) {
            $rights = Entitlement::query()->where('order_id', $order['id'])->orderBy('id')->get();   // index order_id
            $names = $variants->findMany(array_values(array_unique(array_map('intval', $rights->pluck('variant_id')->all()))));
            $byPolicy = $policies->forVariants(array_values(array_unique(array_map('intval', $rights->pluck('variant_id')->all()))));
            $keys = DB::table('license_keys')->whereIn('entitlement_id', $rights->pluck('id')->all())->where('status', 'allocated')->groupBy('entitlement_id')->selectRaw('entitlement_id, COUNT(*) as n')->pluck('n', 'entitlement_id');
            foreach ($rights as $right) {
                $policy = $byPolicy[$right->variant_id] ?? null;
                $rows[] = ['right' => $right, 'product' => $names[$right->variant_id]->productName ?? '', 'sku' => $names[$right->variant_id]->sku ?? '', 'keys' => (int) ($keys[$right->id] ?? 0),
                    'policy' => $policy, 'verdict' => $policy === null ? null : $verdict->for($right, $policy, $clock->now())];
            }
        }

        return view('digital-products::admin.entitlements.index', ['number' => $number, 'order' => $order, 'rows' => $rows, 'can' => $request->user('admin')?->can('digital.manage') === true]);
    }

    public function adjust(AdjustEntitlementRequest $request, AdjustEntitlement $adjust, string $entitlement): RedirectResponse
    {
        $actor = $this->actor($request);
        $amount = (int) $request->validated('amount');

        try {
            match ((string) $request->validated('action')) {
                'extend' => $adjust->extend($actor, $entitlement, $amount),
                'add_downloads' => $adjust->addDownloads($actor, $entitlement, $amount),
                default => $adjust->resetDownloads($actor, $entitlement),
            };
        } catch (DigitalException $e) {
            return back()->withErrors(['entitlement' => __('digital-products::errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('digital-products::admin.entitlements.changed'));
    }

    public function revoke(RevokeEntitlementRequest $request, RevokeEntitlements $revoke, string $entitlement): RedirectResponse
    {
        try {
            $revoke->one($this->actor($request), $entitlement, (string) $request->validated('reason'));
        } catch (DigitalException $e) {
            return back()->withErrors(['entitlement' => __('digital-products::errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('digital-products::admin.entitlements.revoked'));
    }
}
