<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Web;

use App\Modules\Cart\Contracts\CartOwnerResolver;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\AddressChecker;
use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\Orders\Actions\ConfirmCheckoutPhone;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\ReviewCheckout;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Orders\Http\Requests\CheckoutChoicesRequest;
use App\Modules\Orders\Http\Requests\CheckoutDetailsRequest;
use App\Modules\Orders\Http\Requests\PlaceOrderRequest;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CheckoutDraft;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\BanglaText;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class CheckoutController
{
    /** Address field an address problem belongs to. */
    private const FIELD_FOR = ['phone_invalid' => 'phone', 'location_mismatch' => 'upazila_id', 'postal_invalid' => 'postal_code'];

    public function __construct(
        private readonly CartOwnerResolver $owners,
        private readonly CartReader $carts,
        private readonly CheckoutDraft $draft,
        private readonly AddressBook $book,
        private readonly AddressChecker $checker,
        private readonly Geography $geography,
    ) {}

    public function details(Request $request): View|RedirectResponse
    {
        $owner = $this->owners->fromRequest($request);
        $cart = $this->carts->view($owner, app()->getLocale());
        if ($cart->isEmpty() || $cart->hasProblems()) {
            return redirect()->route('cart.show', ['locale' => app()->getLocale()]);
        }

        $userId = $owner->userId;
        $saved = $userId === null ? [] : $this->book->list($userId);
        $user = $request->user('web');
        $needsDelivery = array_filter($cart->lines, fn ($l) => $l->productType === 'physical') !== [];

        return view('orders::checkout.details', [
            'cart' => $cart, 'needsDelivery' => $needsDelivery, 'saved' => $request->boolean('new') ? [] : $saved, 'hasSaved' => $saved !== [],
            'draft' => $this->draft->get(), 'user' => $user,
            'geo' => ['divisions' => $this->geography->divisions(), 'districts' => $this->geography->districtsByDivision(), 'upazilas' => $this->geography->upazilasByDistrict()],
        ]);
    }

    public function saveDetails(CheckoutDetailsRequest $request): RedirectResponse
    {
        $owner = $this->owners->fromRequest($request);
        $cart = $this->carts->view($owner, app()->getLocale());
        if ($cart->isEmpty() || $cart->hasProblems()) {
            return redirect()->route('cart.show', ['locale' => app()->getLocale()]);
        }

        $v = $request->validated();
        $needsDelivery = array_filter($cart->lines, fn ($l) => $l->productType === 'physical') !== [];
        $email = ($v['email'] ?? '') === '' ? null : mb_strtolower(trim((string) $v['email']));

        if (! $needsDelivery) {
            $name = trim(BanglaText::input((string) ($v['contact_name'] ?? '')));
            $phone = PhoneNumber::tryParse(BanglaText::digitsToAscii((string) ($v['contact_phone'] ?? '')));
            if ($name === '' || $phone === null) {
                return back()->withInput()->withErrors([$name === '' ? 'contact_name' : 'contact_phone' => __('orders::checkout.errors.contact_required')]);
            }
            $this->draft->put(['contact_name' => $name, 'contact_phone' => $phone->e164(), 'contact_email' => $email, 'address' => null]);

            return redirect()->route('checkout.review', ['locale' => app()->getLocale()]);
        }

        $userId = $owner->userId;
        if ($userId !== null && ($v['saved_address'] ?? '') !== '') {
            $address = $this->book->find($userId, (string) $v['saved_address']);
            if ($address === null) {
                return back()->withInput()->withErrors(['saved_address' => __('orders::checkout.errors.address_missing')]);
            }
            $this->draft->put(['contact_name' => $address->recipient, 'contact_phone' => $address->phone, 'contact_email' => $email, 'address' => ['mode' => 'saved', 'id' => $address->publicId]]);

            return redirect()->route('checkout.review', ['locale' => app()->getLocale()]);
        }

        Validator::make($v, [
            'recipient' => ['required'], 'phone' => ['required'], 'division_id' => ['required'], 'district_id' => ['required'], 'upazila_id' => ['required'], 'address_line' => ['required'],
        ], [], [
            'recipient' => __('customers::account.addresses.recipient'), 'phone' => __('customers::account.addresses.phone'), 'division_id' => __('customers::account.addresses.division'),
            'district_id' => __('customers::account.addresses.district'), 'upazila_id' => __('customers::account.addresses.upazila'), 'address_line' => __('customers::account.addresses.line'),
        ])->validate();

        $input = AddressInput::fromArray($v);
        try {
            $address = $this->checker->check($input);
            if ($userId !== null && $request->boolean('save_address')) {
                $address = $this->book->save($userId, null, $input);
            }
        } catch (DomainException $e) {
            $reason = $e->getMessage();

            return back()->withInput()->withErrors([self::FIELD_FOR[$reason] ?? 'address_line' => __('customers::account.addresses.errors.'.$reason, ['max' => 10])]);
        }

        $this->draft->put([
            'contact_name' => $address->recipient, 'contact_phone' => $address->phone, 'contact_email' => $email,
            'address' => $address->publicId !== '' ? ['mode' => 'saved', 'id' => $address->publicId] : ['mode' => 'new', 'input' => $v],
        ]);

        return redirect()->route('checkout.review', ['locale' => app()->getLocale()]);
    }

    public function review(Request $request, ReviewCheckout $review): View|RedirectResponse
    {
        $owner = $this->owners->fromRequest($request);
        $draft = $this->draft->get();
        if ($draft === null) {
            return redirect()->route('checkout.details', ['locale' => app()->getLocale()]);
        }

        try {
            $result = $review->handle($owner, $owner->userId, $draft, app()->getLocale());
        } catch (CheckoutException $e) {
            return $this->backToStart($e);
        }

        return view('orders::checkout.review', [
            'review' => $result, 'token' => $this->draft->token(), 'phoneConfirmed' => $this->draft->isPhoneConfirmed($result->contactPhone),
            'maskedPhone' => PhoneNumber::tryParse($result->contactPhone)?->masked() ?? '',
        ]);
    }

    public function choose(CheckoutChoicesRequest $request): RedirectResponse
    {
        $this->draft->choose($request->validated('shipping'), $request->validated('payment'));

        return redirect()->route('checkout.review', ['locale' => app()->getLocale()]);
    }

    public function place(PlaceOrderRequest $request, ReviewCheckout $review, PlaceOrder $place): RedirectResponse
    {
        $owner = $this->owners->fromRequest($request);
        $draft = $this->draft->get();

        if ($draft === null) {
            // the back button after a successful order, or an expired session
            $last = $this->draft->lastOrder();

            return $last !== null ? $this->thanks($last) : redirect()->route('cart.show', ['locale' => app()->getLocale()]);
        }

        $locale = app()->getLocale();
        try {
            $result = $review->handle($owner, $owner->userId, $draft, $locale, $request->validated('shipping'), (string) $request->validated('payment'));
            $order = $place->handle(new PlaceOrderCommand(
                $this->actor($request), $owner, $owner->userId, $result->contactName, $result->contactPhone, $result->contactEmail, $result->address,
                $result->shippingCode, $result->paymentCode, (int) $request->validated('expected_total'), (string) $request->validated('place_token'), $locale,
                phoneConfirmed: $this->draft->isPhoneConfirmed($result->contactPhone),
            ));
        } catch (CheckoutException $e) {
            if ($e->reason === 'cart_empty' && ($last = $this->draft->lastOrder()) !== null) {
                return $this->thanks($last);
            }

            return $this->backToStart($e);
        }

        $this->draft->placed($order->public_id);

        return $this->thanks($order->public_id);
    }

    /** Sends the SMS code for a large cash order to the phone the shopper gave. */
    public function sendPhoneCode(Request $request, ConfirmCheckoutPhone $confirm): RedirectResponse
    {
        $draft = $this->draft->get();
        if ($draft === null || $draft['contact_phone'] === '') {
            return redirect()->route('checkout.details', ['locale' => app()->getLocale()]);
        }

        $status = $confirm->send($draft['contact_phone'], $request->ip(), app()->getLocale());

        return redirect()->route('checkout.review', ['locale' => app()->getLocale()])->with($status === OtpIssueStatus::Sent
            ? 'code_status' : 'code_problem', __('orders::checkout.code.'.($status === OtpIssueStatus::Sent ? 'sent' : 'send_'.$status->value)));
    }

    /** Checks the code. Every failure reads the same, so the page never says which part was wrong. */
    public function verifyPhoneCode(Request $request, ConfirmCheckoutPhone $confirm): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $draft = $this->draft->get();
        if ($draft === null || $draft['contact_phone'] === '') {
            return redirect()->route('checkout.details', ['locale' => app()->getLocale()]);
        }

        if (! $confirm->verify($draft['contact_phone'], $data['code'])) {
            return redirect()->route('checkout.review', ['locale' => app()->getLocale()])->withErrors(['code' => __('orders::checkout.code.invalid')]);
        }

        $this->draft->confirmPhone($draft['contact_phone']);

        return redirect()->route('checkout.review', ['locale' => app()->getLocale()])->with('code_status', __('orders::checkout.code.confirmed'));
    }

    public function thanks(string $orderPublicId): RedirectResponse
    {
        return redirect()->to(URL::temporarySignedRoute('checkout.thanks', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => app()->getLocale(), 'order' => $orderPublicId]));
    }

    public function confirmation(Request $request, string $order): View
    {
        $model = Order::query()->where('public_id', $order)->with(['items', 'addresses'])->firstOrFail();

        return view('orders::checkout.thanks', ['order' => $model]);
    }

    private function backToStart(CheckoutException $e): RedirectResponse
    {
        $message = __('orders::checkout.errors.'.$e->reason, $e->params);

        return match ($e->reason) {
            'cart_empty', 'cart_problem' => redirect()->route('cart.show', ['locale' => app()->getLocale()])->withErrors(['cart' => $message]),
            'address_missing' => redirect()->route('checkout.details', ['locale' => app()->getLocale()])->withErrors(['checkout' => $message]),
            default => redirect()->route('checkout.review', ['locale' => app()->getLocale()])->withErrors(['checkout' => $message]),
        };
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('web');

        return $user === null ? Actor::guest('checkout') : new Actor(ActorType::Customer, (int) $user->getAuthIdentifier(), $user->public_id);
    }
}
