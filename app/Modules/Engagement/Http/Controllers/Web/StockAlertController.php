<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Web;

use App\Modules\Engagement\Actions\CancelStockAlert;
use App\Modules\Engagement\Actions\SubscribeStockAlert;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Requests\VariantRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Tell me when it is back": asking, and no longer wanting to be told. Always the signed-in customer's own. */
class StockAlertController
{
    public function add(VariantRequest $request, SubscribeStockAlert $subscribe): RedirectResponse
    {
        try {
            $subscribe->handle((int) $request->user('web')?->getAuthIdentifier(), (string) $request->validated('variant'), app()->getLocale());
        } catch (EngagementException $e) {
            return back()->withErrors(['wishlist' => __('engagement::errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('engagement::wishlist.alert_set_status'));
    }

    public function remove(Request $request, CancelStockAlert $cancel, string $variant): RedirectResponse
    {
        $cancel->handle((int) $request->user('web')?->getAuthIdentifier(), $variant);

        return back()->with('status', __('engagement::wishlist.alert_cancelled_status'));
    }
}
