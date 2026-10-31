<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Payments\Actions\ApproveRefund;
use App\Modules\Payments\Actions\CompleteManualRefund;
use App\Modules\Payments\Actions\ExecuteRefund;
use App\Modules\Payments\Actions\RejectRefund;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Http\Concerns\ActsAsStaff;
use App\Modules\Payments\Http\Concerns\ParsesMoney;
use App\Modules\Payments\Http\Requests\RefundProofRequest;
use App\Modules\Payments\Http\Requests\RefundReasonRequest;
use App\Modules\Payments\Http\Requests\RequestRefundRequest;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RefundController
{
    use ActsAsStaff;
    use ParsesMoney;

    public const PER_PAGE = 25;

    /** The queue: refunds waiting for a decision, for sending, or with the gateway. */
    public function index(Request $request, OrderDirectory $orders, StaffDirectory $staff): View
    {
        $refunds = Refund::query()->whereIn('status', ['requested', 'approved', 'processing'])->orderBy('id')->paginate(self::PER_PAGE);
        $payments = Payment::query()->whereIn('id', $refunds->pluck('payment_id')->all())->get()->keyBy('id');

        return view('payments::admin.refunds.index', [
            'refunds' => $refunds, 'payments' => $payments,
            'orders' => $orders->references(array_values(array_map('intval', $refunds->pluck('order_id')->all()))),
            'staff' => $staff->names(array_values(array_merge($refunds->pluck('requested_by')->all(), $refunds->pluck('approved_by')->all()))),
            'mine' => (int) $request->user('admin')?->getAuthIdentifier(),
            'can' => $request->user('admin')?->can('payments.refund') === true,
        ]);
    }

    public function request(RequestRefundRequest $request, RequestRefund $refund, string $payment): RedirectResponse
    {
        try {
            $refund->handle($this->actor($request), $payment, $this->minor((string) $request->validated('amount')), (string) $request->validated('reason'), $request->validated('attempt') ?: null, $request->boolean('manual'));
        } catch (PaymentException $e) {
            return back()->withErrors(['refund' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.refund_requested'));
    }

    public function approve(Request $request, ApproveRefund $approve, string $refund): RedirectResponse
    {
        return $this->run($request, fn () => $approve->handle($this->actor($request), $refund), 'refund_approved');
    }

    public function reject(RefundReasonRequest $request, RejectRefund $reject, string $refund): RedirectResponse
    {
        return $this->run($request, fn () => $reject->handle($this->actor($request), $refund, (string) $request->validated('reason')), 'refund_rejected');
    }

    public function send(Request $request, ExecuteRefund $execute, string $refund): RedirectResponse
    {
        return $this->run($request, fn () => $execute->handle($this->actor($request), $refund), 'refund_sent');
    }

    public function complete(RefundProofRequest $request, CompleteManualRefund $complete, string $refund): RedirectResponse
    {
        return $this->run($request, fn () => $complete->handle($this->actor($request), $refund, (string) $request->validated('proof')), 'refund_completed');
    }

    /** @param  callable(): mixed  $work */
    private function run(Request $request, callable $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (PaymentException $e) {
            return back()->withErrors(['refund' => __('payments::payments.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('payments::admin.'.$done));
    }
}
