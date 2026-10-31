<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Payments\Actions\ManagePaymentSettings;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Http\Concerns\ActsAsStaff;
use App\Modules\Payments\Http\Concerns\ParsesMoney;
use App\Modules\Payments\Http\Requests\FreezeRequest;
use App\Modules\Payments\Http\Requests\UpdatePaymentSettingsRequest;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentHealth;
use App\Modules\Payments\Services\ProviderHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController
{
    use ActsAsStaff;
    use ParsesMoney;

    public function show(Request $request, ManagePaymentSettings $settings, ProviderHealth $health, PaymentHealth $signals): View
    {
        $user = $request->user('admin');

        return view('payments::admin.settings', [
            'methods' => PaymentMethod::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get(),
            'frozen' => $settings->frozen(), 'refundLimit' => $settings->refundLimit(), 'health' => $health->report(), 'alerts' => array_filter($signals->snapshot(), fn (array $s): bool => $s['breached']),
            'can' => ['manage' => $user?->can('settings.manage') === true, 'freeze' => $user?->can('payments.reconcile') === true],
        ]);
    }

    public function update(UpdatePaymentSettingsRequest $request, ManagePaymentSettings $settings): RedirectResponse
    {
        try {
            $actor = $this->actor($request);
            $settings->setRefundLimit($actor, $this->minor((string) $request->validated('refund_limit')));
            foreach ((array) $request->validated('methods', []) as $code => $enabled) {
                $settings->setMethodEnabled($actor, (string) $code, (bool) $enabled);
            }
        } catch (PaymentException $e) {
            return back()->withErrors(['settings' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.settings_saved'));
    }

    public function freeze(FreezeRequest $request, ManagePaymentSettings $settings): RedirectResponse
    {
        $settings->freeze($this->actor($request), $request->boolean('frozen'));

        return back()->with('status', __($request->boolean('frozen') ? 'payments::admin.frozen_on' : 'payments::admin.frozen_off'));
    }
}
