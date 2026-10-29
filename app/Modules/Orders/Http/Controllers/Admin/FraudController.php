<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\ManageBlocklist;
use App\Modules\Orders\Actions\SaveFraudSettings;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Http\Requests\FraudSettingsRequest;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The cash-on-delivery abuse rules: their limits, and the list of phones and addresses that may not order. */
class FraudController
{
    public function settings(SaveFraudSettings $save): View
    {
        $v = $save->current();

        return view('orders::admin.fraud.settings', ['v' => $v, 'highValue' => (int) $v['fraud.high_value_cod_minor'] === 0 ? '0' : Money::of((int) $v['fraud.high_value_cod_minor'])->toMajorString()]);
    }

    public function updateSettings(FraudSettingsRequest $request, SaveFraudSettings $save): RedirectResponse
    {
        try {
            $save->handle($this->actor($request), $request->settings());
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['settings' => __('orders::fraud.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.orders.fraud.settings')->with('status', __('orders::fraud.saved'));
    }

    public function blocklist(): View
    {
        return view('orders::admin.fraud.blocklist', [
            'entries' => DB::table('order_blocklist')->orderByDesc('is_active')->orderByDesc('id')->paginate(50),
            'now' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function addPhone(Request $request, ManageBlocklist $manage): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'reason' => ['nullable', 'string', 'max:255']]);

        try {
            $manage->addPhone($this->actor($request), $data['phone'], $data['reason'] ?? null);
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['phone' => __('orders::fraud.errors.'.$e->reason)]);
        }

        return redirect()->route('admin.orders.fraud.blocklist')->with('status', __('orders::fraud.added'));
    }

    public function toggle(Request $request, int $entry, ManageBlocklist $manage): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        try {
            $manage->setActive($this->actor($request), $entry, (bool) $data['active']);
        } catch (OrderException) {
            abort(404);
        }

        return redirect()->route('admin.orders.fraud.blocklist')->with('status', __('orders::fraud.updated'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
