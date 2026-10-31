<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Payments\Actions\VerifyPaymentManually;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Http\Concerns\ActsAsStaff;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Models\ReconciliationException;
use App\Modules\Payments\Models\Refund;
use App\Modules\Platform\Support\Redactor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController
{
    use ActsAsStaff;

    public const PER_PAGE = 25;

    public function index(Request $request, OrderDirectory $orders): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:'.implode(',', array_column(PaymentState::cases(), 'value'))],
            'provider' => ['nullable', 'string', 'max:30'],
        ]);

        $payments = Payment::query()
            ->when($filters['q'] ?? null, function ($q, string $term) use ($orders) {
                $term = trim($term);
                $orderId = $orders->findByNumber($term)['id'] ?? null;

                return $q->where(fn ($w) => $w
                    ->where('public_id', strtolower($term))
                    ->when($orderId !== null, fn ($x) => $x->orWhere('order_id', $orderId))
                    ->orWhereIn('id', fn ($sub) => $sub->select('payment_id')->from('payment_transactions')->where('merchant_ref', $term)->orWhere('provider_txn_id', $term)));
            })
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['provider'] ?? null, fn ($q, string $v) => $q->where('provider', $v))
            ->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return view('payments::admin.payments.index', ['payments' => $payments, 'filters' => $filters, 'orders' => $orders->references(array_values(array_map('intval', $payments->pluck('order_id')->all())))]);
    }

    public function show(Request $request, OrderDirectory $orders, StaffDirectory $staff, string $payment): View
    {
        $payment = Payment::query()->where('public_id', $payment)->firstOrFail();
        $payment->load('transactions');
        $refunds = Refund::query()->where('payment_id', $payment->id)->orderBy('id')->get();
        $staff = $staff->names(array_values(array_merge($refunds->pluck('requested_by')->all(), $refunds->pluck('approved_by')->all())));

        return view('payments::admin.payments.show', [
            'payment' => $payment,
            'order' => $orders->references([$payment->order_id])[$payment->order_id] ?? null,
            'refunds' => $refunds,
            'staff' => $staff,
            // what the gateway sent, with secrets, card numbers and phone numbers removed (the stored copy is never changed)
            'events' => PaymentWebhookEvent::query()->where('payment_id', $payment->id)->orderByDesc('id')->limit(50)->get()->map(fn (PaymentWebhookEvent $e): array => ['event' => $e, 'payload' => Redactor::array($e->payload)]),
            'exceptions' => ReconciliationException::query()->where('payment_id', $payment->id)->orderByDesc('id')->get(),
            'can' => ['refund' => $request->user('admin')?->can('payments.refund') === true, 'reconcile' => $request->user('admin')?->can('payments.reconcile') === true],
        ]);
    }

    public function verify(Request $request, VerifyPaymentManually $verify, string $payment): RedirectResponse
    {
        try {
            $outcomes = $verify->handle($this->actor($request), $payment);
        } catch (PaymentException $e) {
            return back()->withErrors(['verify' => __('payments::payments.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('payments::admin.verified', ['result' => $outcomes === [] ? __('payments::admin.verify_nothing') : implode(', ', array_map(fn ($ref, $o) => $ref.': '.__('payments::admin.outcome.'.$o), array_keys($outcomes), $outcomes))]));
    }
}
