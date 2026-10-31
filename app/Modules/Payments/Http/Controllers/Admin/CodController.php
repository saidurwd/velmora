<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Payments\Actions\RecordCodRemittance;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Http\Concerns\ActsAsStaff;
use App\Modules\Payments\Http\Concerns\ParsesMoney;
use App\Modules\Payments\Http\Requests\RecordCollectionRequest;
use App\Modules\Payments\Http\Requests\RecordRemittanceRequest;
use App\Modules\Payments\Models\CodCollection;
use App\Modules\Payments\Models\CodRemittance;
use App\Modules\Payments\Services\CodAging;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CodController
{
    use ActsAsStaff;
    use ParsesMoney;

    public function index(Request $request, OrderDirectory $orders, CodAging $aging): View
    {
        // collections no courier statement covers yet (the NOT EXISTS uses the UNIQUE on cod_remittance_items.cod_collection_id)
        $unremitted = CodCollection::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cod_remittance_items as i')->whereColumn('i.cod_collection_id', 'cod_collections.id'))->orderBy('collected_at')->limit(200)->get();
        $remittances = CodRemittance::query()->latest('id')->limit(20)->get();

        return view('payments::admin.cod.index', [
            'aging' => $aging->report(), 'unremitted' => $unremitted, 'remittances' => $remittances,
            'orders' => $orders->references(array_values(array_map('intval', $unremitted->pluck('order_id')->all()))),
            'can' => $request->user('admin')?->can('payments.reconcile') === true,
        ]);
    }

    public function collect(RecordCollectionRequest $request, OrderDirectory $orders, RecordCodCollection $collect, Clock $clock): RedirectResponse
    {
        $order = $orders->findByNumber((string) $request->validated('order_number'));
        if ($order === null) {
            return back()->withErrors(['collect' => __('payments::payments.errors.cod_order_not_found')])->withInput();
        }

        try {
            $collect->handle($this->actor($request), $order['public_id'], $this->day($request->validated('collected_on'), $clock), $request->validated('note'));
        } catch (PaymentException $e) {
            return back()->withErrors(['collect' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.cod_collected'));
    }

    public function remit(RecordRemittanceRequest $request, RecordCodRemittance $remit, Clock $clock): RedirectResponse
    {
        try {
            $items = [];
            foreach ((array) $request->validated('parcels') as $parcel) {
                $items[] = ['collection' => (string) $parcel, 'amount_minor' => $this->minor((string) ($request->validated('amounts')[$parcel] ?? ''))];
            }
            $remit->handle($this->actor($request), (string) $request->validated('courier'), (string) $request->validated('statement'), $this->minor((string) $request->validated('received')), $this->day($request->validated('received_on'), $clock), $items);
        } catch (PaymentException $e) {
            return back()->withErrors(['remit' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.cod_remitted'));
    }

    /** A date typed by staff means that day in Dhaka (noon), never in the future; empty means now. */
    private function day(mixed $date, Clock $clock): ?CarbonImmutable
    {
        if (! is_string($date) || $date === '') {
            return null;
        }
        $noon = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' 12:00:00', 'Asia/Dhaka')?->utc();

        return $noon === null || $noon->greaterThan($clock->now()) ? null : $noon;
    }
}
