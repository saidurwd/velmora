<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Web;

use App\Modules\IdentityAccess\Contracts\CustomerRegistration;
use App\Modules\Orders\Actions\CancelOwnOrder;
use App\Modules\Orders\Actions\LinkOrderToCustomer;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Requests\CreateAccountFromOrderRequest;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/** "My orders" for a signed-in customer, and the two ways a guest order becomes theirs. Another customer's order is simply "not found". */
class AccountOrderController
{
    public const PER_PAGE = 10;

    public function __construct(private readonly PaymentMethodCatalog $methods) {}

    public function index(Request $request): View
    {
        return view('orders::account.index', ['orders' => Order::query()->where('user_id', $this->userId($request))->latest('id')->paginate(self::PER_PAGE)]);
    }

    public function show(Request $request, string $order): View
    {
        $model = Order::query()->where('user_id', $this->userId($request))->where('public_id', $order)->with(['items', 'addresses'])->firstOrFail();
        $open = ! in_array($model->status->value, ['cancelled', 'completed'], true) && in_array($model->payment_status->value, ['unpaid', 'pending', 'failed'], true);
        $online = $model->payment_method_code !== 'cod';

        return view('orders::account.show', [
            'order' => $model, 'online' => $online, 'methodLabel' => $this->methods->label($model->payment_method_code, app()->getLocale()),
            'payUrl' => $online && $open && Route::has('payments.pay') ? URL::temporarySignedRoute('payments.pay', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => app()->getLocale(), 'order' => $model->public_id]) : null,
            'cancelDenied' => CancelOwnOrder::deniedReason($model),
            'notes' => $model->notes()->where('visibility', 'customer')->get(),
        ]);
    }

    public function cancel(CancelOrderRequest $request, CancelOwnOrder $cancel, string $order): RedirectResponse
    {
        try {
            $cancel->handle($this->actor($request), $order, $request->validated('note'));
        } catch (OrderException $e) {
            return back()->withErrors(['cancel' => __('orders::account.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('orders::account.cancelled'));
    }

    /** From the signed confirmation page: a guest makes an account and this order goes into it. Same answer whether or not the address already had an account. */
    public function createAccount(CreateAccountFromOrderRequest $request, CustomerRegistration $accounts, LinkOrderToCustomer $link, string $order): RedirectResponse
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        if ($model->user_id === null) {
            $userId = $accounts->registerNew((string) $request->validated('name'), (string) $request->validated('email'), (string) $request->validated('password'), app()->getLocale());
            if ($userId !== null) {
                try {
                    $link->handle(Actor::guest('order-confirmation'), $model->public_id, $userId);
                } catch (OrderException) {
                    // another person claimed it a moment ago: the new account simply starts empty
                }
            }
        }

        return redirect()->to($this->thanks($model->public_id))->with('account_status', 'started');
    }

    /** A signed-in customer holding the signed confirmation link adds the order to their account. */
    public function attach(Request $request, LinkOrderToCustomer $link, string $order): RedirectResponse
    {
        Order::query()->where('public_id', $order)->firstOrFail();

        try {
            $link->handle($this->actor($request), $order, $this->userId($request));
        } catch (OrderException $e) {
            return redirect()->to($this->thanks($order))->withErrors(['account' => __('orders::account.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('account.orders.show', ['locale' => app()->getLocale(), 'order' => $order])->with('status', __('orders::account.attached'));
    }

    private function thanks(string $order): string
    {
        return URL::temporarySignedRoute('checkout.thanks', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => app()->getLocale(), 'order' => $order]);
    }

    private function userId(Request $request): int
    {
        $user = $request->user('web');
        abort_if($user === null, 403);

        return (int) $user->getAuthIdentifier();
    }

    private function actor(Request $request): Actor
    {
        return new Actor(ActorType::Customer, $this->userId($request), $request->user('web')?->public_id);
    }
}
