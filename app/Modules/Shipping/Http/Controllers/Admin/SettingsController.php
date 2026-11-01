<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\SaveFulfilmentSettings;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Concerns\ActsAsStaff;
use App\Modules\Shipping\Http\Requests\Admin\FulfilmentSettingsRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController
{
    use ActsAsStaff;

    public function show(Request $request, SaveFulfilmentSettings $settings): View
    {
        return view('shipping::admin.settings', ['values' => $settings->current(), 'can' => $request->user('admin')?->can('settings.manage') === true]);
    }

    public function update(FulfilmentSettingsRequest $request, SaveFulfilmentSettings $settings): RedirectResponse
    {
        try {
            $settings->handle($this->actor($request), (int) $request->validated('window'), (int) $request->validated('attempts'));
        } catch (ShipmentException $e) {
            return back()->withErrors(['settings' => __('shipping::shipments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('shipping::fulfilment.settings.saved'));
    }
}
