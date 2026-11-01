<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Shipping\Actions\ImportCourierStatement;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Concerns\ActsAsStaff;
use App\Modules\Shipping\Http\Requests\Admin\ImportStatementRequest;
use App\Modules\Shipping\Models\CourierAccount;
use App\Modules\Shipping\Models\CourierStatement;
use App\Modules\Shipping\Services\CourierRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A courier's statement: upload it, see what matched and what did not. The cash it records is the Payments module's (through its contract). */
class StatementController
{
    use ActsAsStaff;

    public function index(Request $request, CourierRegistry $couriers): View
    {
        return view('shipping::admin.statements.index', [
            'statements' => CourierStatement::query()->orderByDesc('id')->paginate(20),
            'couriers' => CourierAccount::query()->distinct()->orderBy('courier')->pluck('courier')->filter(fn ($c) => $couriers->has((string) $c))->values(),
            'can' => $request->user('admin')?->can('payments.reconcile') === true,
        ]);
    }

    public function show(string $statement): View
    {
        $found = CourierStatement::query()->where('public_id', $statement)->firstOrFail();

        return view('shipping::admin.statements.show', [
            'statement' => $found,
            'lines' => DB::table('courier_statement_lines')->where('statement_id', $found->id)->orderByRaw("result = 'matched'")->orderBy('id')->limit(500)->get(),
        ]);
    }

    public function import(ImportStatementRequest $request, ImportCourierStatement $import, Clock $clock): RedirectResponse
    {
        $csv = (string) $request->file('file')?->get();
        if (! mb_check_encoding($csv, 'UTF-8')) {
            return back()->withErrors(['statement' => __('shipping::fulfilment.statements.not_utf8')])->withInput();
        }

        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $request->validated('from').' 00:00:00', 'Asia/Dhaka')?->utc();
            $to = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $request->validated('to').' 23:59:59', 'Asia/Dhaka')?->utc();
            abort_if($from === null || $to === null, 422);
            $to = $to->greaterThan($clock->now()) ? $clock->now() : $to;   // a period that ends today ends now
            $saved = $import->handle($this->actor($request), (string) $request->validated('courier'), (string) $request->validated('statement'), $csv, $from, $to, $this->minor((string) $request->validated('received')));
        } catch (ShipmentException $e) {
            return back()->withErrors(['statement' => __('shipping::shipments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.shipping.statements.show', $saved->public_id)->with('status', __('shipping::fulfilment.statements.imported'));
    }

    /** "1500.50" or "১৫০০.৫০" in taka, to paisa exactly (never a float). */
    private function minor(string $amount): int
    {
        $ascii = BanglaText::digitsToAscii(trim($amount));
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $ascii) !== 1) {
            throw new ShipmentException('statement_invalid', ['errors' => __('shipping::fulfilment.statements.amount_invalid')]);
        }
        [$whole, $part] = array_pad(explode('.', $ascii), 2, '0');

        return Money::of((int) $whole * 100 + (int) str_pad($part, 2, '0'), 'BDT')->amountMinor;
    }
}
