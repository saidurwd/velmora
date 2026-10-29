<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\AddOrderNote;
use App\Modules\Orders\Actions\BlockContact;
use App\Modules\Orders\Actions\ClearOrderReview;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Http\Requests\OrderNoteRequest;
use App\Modules\Orders\Http\Requests\TransitionOrderRequest;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:'.implode(',', array_column(OrderStatus::cases(), 'value'))],
            'payment' => ['nullable', 'in:'.implode(',', array_column(PaymentStatus::cases(), 'value'))],
            'fulfillment' => ['nullable', 'in:'.implode(',', array_column(FulfillmentStatus::cases(), 'value'))],
            'review' => ['nullable', 'in:1'],
        ]);

        $orders = Order::query()
            ->when($filters['q'] ?? null, function ($q, string $term) {
                $term = trim($term);
                $like = addcslashes($term, '%_\\');

                return $q->where(fn ($w) => $w
                    ->where('order_number', 'like', strtoupper($like).'%')
                    ->orWhere('contact_phone', 'like', preg_replace('/\D/', '', $like) === '' ? '__none__' : '%'.ltrim((string) preg_replace('/\D/', '', $like), '0').'%')
                    ->orWhere('contact_name', 'like', '%'.$like.'%'));
            })
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['payment'] ?? null, fn ($q, string $v) => $q->where('payment_status', $v))
            ->when($filters['fulfillment'] ?? null, fn ($q, string $v) => $q->where('fulfillment_status', $v))
            ->when($filters['review'] ?? null, fn ($q) => $q->where('needs_review', true))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('orders::admin.index', ['orders' => $orders, 'filters' => $filters, 'canSeePhone' => $request->user('admin')?->can('customers.view_pii') === true]);
    }

    public function show(Request $request, Order $order): View
    {
        $order->load(['items', 'addresses', 'history', 'notes', 'taxLines', 'flags']);

        return view('orders::admin.show', [
            'order' => $order,
            'canSeePhone' => $request->user('admin')?->can('customers.view_pii') === true,
            'canCancel' => $request->user('admin')?->can('orders.cancel') === true,
        ]);
    }

    public function transition(TransitionOrderRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $axis = (string) $request->validated('axis');
        $to = (string) $request->validated('to');
        $reason = $request->validated('reason');
        $reason = is_string($reason) ? $reason : null;

        if ($to === OrderStatus::Cancelled->value) {
            abort_unless($request->user('admin')?->can('orders.cancel') === true, 403);
        }

        try {
            $actor = $this->actor($request);
            match ($axis) {
                'status' => $transition->status($actor, $order->id, OrderStatus::tryFrom($to) ?? throw new OrderException('illegal_transition'), $reason),
                'payment' => $transition->payment($actor, $order->id, PaymentStatus::tryFrom($to) ?? throw new OrderException('illegal_transition'), $reason),
                default => $transition->fulfillment($actor, $order->id, FulfillmentStatus::tryFrom($to) ?? throw new OrderException('illegal_transition'), $reason),
            };
        } catch (OrderException $e) {
            return back()->withErrors(['transition' => __('orders::orders.errors.'.$e->reason, $e->params)])->withInput();
        }

        return back()->with('status', __('orders::orders.updated'));
    }

    public function note(OrderNoteRequest $request, Order $order, AddOrderNote $add): RedirectResponse
    {
        $add->handle($this->actor($request), $order, (string) $request->validated('body'), (string) $request->validated('visibility'));

        return back()->with('status', __('orders::orders.note_added'));
    }

    public function clearReview(Request $request, Order $order, ClearOrderReview $clear): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $clear->handle($this->actor($request), $order->id, $data['reason']);
        } catch (OrderException $e) {
            return back()->withErrors(['review' => __('orders::orders.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('orders::orders.review_cleared'));
    }

    public function block(Request $request, Order $order, BlockContact $block): RedirectResponse
    {
        $data = $request->validate(['phone' => ['sometimes', 'boolean'], 'address' => ['sometimes', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $count = $block->handle($this->actor($request), $order, $request->boolean('phone'), $request->boolean('address'), $data['reason'] ?? null);

        return $count === 0 ? back()->withErrors(['review' => __('orders::orders.block_nothing')]) : back()->with('status', __('orders::orders.blocked'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
