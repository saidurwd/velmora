<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\CancelStalePendingOrders;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Listeners\ApplyPaymentEvent;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Actions\ExpireUnpaidPayments;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class, PaymentMethodSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->make = function (string $sku, string $en, string $bn, int $taka, int $stock = 10, ProductType $type = ProductType::Physical) use ($actor, $category): int {
        $product = app(SaveProduct::class)->handle($actor, null, new ProductData($type, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$category->id],
            defaultVariant: new VariantData($sku, trackInventory: $type === ProductType::Physical, weightG: $type === ProductType::Physical ? 300 : null)));
        $variantId = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle($actor, $variantId, Money::of($taka * 100));
        if ($type === ProductType::Physical) {
            DB::table('stock_levels')->insert(['variant_id' => $variantId, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => $stock, 'reserved' => 0, 'updated_at' => now()]);
        }
        app(TransitionProduct::class)->publish($actor, $product);

        return $variantId;
    };
    $this->tee = ($this->make)('TEE-1', 'Cotton Tee', 'সুতির টি-শার্ট', 650, 10);
    $this->ebook = ($this->make)('EBOOK-1', 'E-book', 'ই-বুক', 200, 0, ProductType::Digital);

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
    $this->token = str_repeat('e', 40);
    $this->cart = fn (int $variant, int $qty = 1) => app(AddToCart::class)->handle(CartOwner::guest($this->token), $variant, $qty);
    $this->as = fn () => $this->withCookie(GuestCartToken::COOKIE, $this->token);
    $this->physicalForm = fn (array $over = []) => array_replace(['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2'], $over);
    $this->review = function (?array $form = null) {
        ($this->as)()->post('/en/checkout', $form ?? ($this->physicalForm)())->assertRedirect('/en/checkout/review');
        $html = ($this->as)()->get('/en/checkout/review')->getContent();
        preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);

        return ['place_token' => $t[1], 'expected_total' => $e[1], 'shipping' => 'standard'];
    };
    $this->placeOnline = fn (array $over = []) => ($this->as)()->post('/en/checkout/place', ($this->review)() + ['payment' => 'online'] + $over);
    $this->paidAnswer = fn (string $ref, int $minor) => new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of($minor), 'BANK-'.$ref, 'VAL-'.$ref);
    $this->order = fn () => Order::query()->latest('id')->firstOrFail();
});

describe('choosing to pay online at checkout', function () {
    it('offers the methods the owner switched on, labelled in the shopper\'s language, with the cash fee only on cash', function () {
        ($this->cart)($this->tee, 2);
        DB::table('shipping_methods')->where('code', 'standard')->update(['cod_fee_minor' => 3000]);
        ($this->review)();

        ($this->as)()->get('/en/checkout/review')->assertOk()->assertSee('Cash on delivery')->assertSee('Pay online')->assertSee('bKash, cards and internet banking');
        ($this->as)()->get('/bn/checkout/review')->assertOk()->assertSee('ক্যাশ অন ডেলিভারি')->assertSee('অনলাইনে পেমেন্ট');
        ($this->as)()->post('/en/checkout/review', ['shipping' => 'standard', 'payment' => 'online'])->assertRedirect();
        ($this->as)()->get('/en/checkout/review')->assertDontSee('+৳')->assertSee('1,360.00');   // ৳1,300 + ৳60 delivery, no cash fee for an online payment
    });

    it('hides online payment when it is switched off, frozen, or the order is below the method\'s minimum; cash stays', function () {
        ($this->cart)($this->tee, 1);
        ($this->review)();
        $page = fn () => ($this->as)()->get('/en/checkout/review')->assertOk();

        DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => false]);
        $page()->assertDontSee('Pay online')->assertSee('Cash on delivery');
        DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => true]);
        app(Settings::class)->set('payments.online_frozen', true);
        $page()->assertDontSee('Pay online')->assertSee('Cash on delivery');
        app(Settings::class)->set('payments.online_frozen', false);
        DB::table('payment_methods')->where('code', 'online')->update(['min_minor' => 1_000_000]);
        $page()->assertDontSee('Pay online')->assertSee('Cash on delivery');
        DB::table('payment_methods')->where('code', 'online')->update(['min_minor' => null]);
        $page()->assertSee('Pay online');
    });

    it('places the order unpaid, holds the stock, and sends the shopper to the gateway', function () {
        ($this->cart)($this->tee, 2);

        $r = ($this->placeOnline)();

        $r->assertRedirect();
        $order = ($this->order)();
        expect($r->headers->get('Location'))->toBe('https://fake-gateway.test/pay/'.$order->order_number.'-1')
            ->and($order->payment_method_code)->toBe('online')->and($order->payment_status->value)->toBe('pending')->and($order->status->value)->toBe('pending')
            ->and($order->cod_fee_minor)->toBe(0)->and($order->grand_total_minor)->toBe(136000)
            ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(2)->and(DB::table('cart_items')->count())->toBe(0)
            ->and(DB::table('payments')->where('order_id', $order->id)->value('status'))->toBe('pending')
            ->and(DB::table('order_status_history')->where('order_id', $order->id)->where('axis', 'payment')->orderBy('id')->pluck('to_state')->all())->toBe(['unpaid', 'pending'])
            ->and($this->gateway->initiated[0]->amount->amountMinor)->toBe(136000)->and($this->gateway->initiated[0]->customerPhone)->toBe('+8801712345678');
    });

    it('lets a digital-only cart be bought: contact details, no address, paid online', function () {
        ($this->cart)($this->ebook, 1);
        ($this->as)()->post('/en/checkout', ['contact_name' => 'Rahim', 'contact_phone' => '01712345678'])->assertRedirect('/en/checkout/review');
        $html = ($this->as)()->get('/en/checkout/review')->assertOk()->assertSee('Pay online')->assertDontSee('Cash on delivery')->getContent();
        preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);

        $r = ($this->as)()->post('/en/checkout/place', ['place_token' => $t[1], 'expected_total' => $e[1], 'payment' => 'online']);

        $r->assertRedirectContains('https://fake-gateway.test/pay/');
        expect(($this->order)()->grand_total_minor)->toBe(20000)->and(DB::table('order_addresses')->count())->toBe(0);
    });

    it('is not held back by the cash-order rules: four online orders from one phone are fine', function () {
        app(Settings::class)->set('fraud.max_open_cod_per_phone', 1);
        foreach (range(1, 4) as $i) {
            ($this->cart)($this->tee, 1);
            ($this->placeOnline)()->assertRedirectContains('fake-gateway.test');
        }

        expect(Order::count())->toBe(4);
    });

    it('does not start a second payment when the place button is pressed twice', function () {
        ($this->cart)($this->tee, 1);
        $form = ($this->review)() + ['payment' => 'online'];

        $a = ($this->as)()->post('/en/checkout/place', $form)->assertRedirect()->headers->get('Location');
        $b = ($this->as)()->post('/en/checkout/place', $form)->assertRedirect()->headers->get('Location');   // the second press lands on the order page, which has "Pay now"

        expect($a)->toStartWith('https://fake-gateway.test/pay/')->and($b)->toContain('/thanks')->and(Order::count())->toBe(1)->and(DB::table('payments')->count())->toBe(1)->and(DB::table('payment_transactions')->count())->toBe(1);
    });

    it('keeps the order and offers to try again when the gateway cannot be reached', function () {
        ($this->cart)($this->tee, 1);
        $this->gateway->failNext('initiate');

        $r = ($this->placeOnline)();

        $order = ($this->order)();
        $r->assertRedirectContains("/en/orders/{$order->public_id}/thanks")->assertSessionHas('payment_error', 'initiation_failed');
        $page = $this->get($r->headers->get('Location'))->assertOk();
        expect($order->payment_status->value)->toBe('unpaid')->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(1);
        $page->assertSee('Please complete your payment')->assertSee('Pay now')->assertSee('We could not reach the payment service');
    });
});

describe('the order follows its payment', function () {
    beforeEach(function () {
        ($this->cart)($this->tee, 2);
        $this->go = ($this->placeOnline)();
        $this->orderRow = ($this->order)();
        $this->ref = $this->orderRow->order_number.'-1';
        $this->state = fn () => DB::table('orders')->where('id', $this->orderRow->id)->first(['status', 'payment_status', 'needs_review']);
        $this->returnUrl = fn (string $which = 'successUrl') => $this->gateway->initiated[0]->{$which};
    });

    it('is paid and confirmed once the gateway confirms the money, and says so in both languages', function () {
        $this->gateway->answer($this->ref, ($this->paidAnswer)($this->ref, 136000));

        $r = $this->get(($this->returnUrl)())->assertRedirect();

        expect(($this->state)()->payment_status)->toBe('paid')->and(($this->state)()->status)->toBe('confirmed')
            ->and(DB::table('order_status_history')->where('order_id', $this->orderRow->id)->where('axis', 'status')->orderByDesc('id')->first(['to_state', 'actor_type', 'reason']))->toMatchArray(['to_state' => 'confirmed', 'actor_type' => 'system', 'reason' => 'paid online']);
        $page = $this->get($r->headers->get('Location'));
        $page->assertOk()->assertSee('Payment received')->assertDontSee('Pay now')->assertDontSee('Try payment again');
        $this->get(URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'bn', 'order' => $this->orderRow->public_id]))->assertSee('পেমেন্ট পেয়েছি');
    });

    it('stays unconfirmed (but paid) if the order is flagged for review', function () {
        DB::table('orders')->where('id', $this->orderRow->id)->update(['needs_review' => true]);
        $this->gateway->answer($this->ref, ($this->paidAnswer)($this->ref, 136000));

        $this->get(($this->returnUrl)());

        expect(($this->state)()->payment_status)->toBe('paid')->and(($this->state)()->status)->toBe('pending');
    });

    it('is not paid by a forged success page: the order stays waiting, and the page says it is checking', function () {
        $r = $this->get(($this->returnUrl)())->assertRedirect();   // the gateway has no money for this reference

        expect(($this->state)()->payment_status)->toBe('pending')->and(($this->state)()->status)->toBe('pending');
        $this->get($r->headers->get('Location'))->assertSee('We are confirming your payment')->assertSee('Check again');
    });

    it('shows a failed attempt with a way to try again, and the second attempt can succeed', function () {
        $this->gateway->answer($this->ref, new VerifiedPayment(VerifiedStatus::Failed, $this->ref, null));
        $r = $this->get(($this->returnUrl)('failUrl'))->assertRedirect();
        expect(($this->state)()->payment_status)->toBe('failed');

        $page = $this->get($r->headers->get('Location'))->assertOk()->assertSee('Try payment again');
        preg_match('#href="([^"]*/pay/order/[^"]+)"#', html_entity_decode($page->getContent()), $m);
        $this->get($m[1])->assertRedirect('https://fake-gateway.test/pay/'.$this->orderRow->order_number.'-2');
        expect(($this->state)()->payment_status)->toBe('pending');

        $ref2 = $this->orderRow->order_number.'-2';
        $this->gateway->answer($ref2, ($this->paidAnswer)($ref2, 136000));
        app(VerifyPayment::class)->handle($ref2);

        expect(($this->state)()->payment_status)->toBe('paid')->and(($this->state)()->status)->toBe('confirmed');
    });

    it('is cancelled, with its stock given back, when the time to pay runs out', function () {
        DB::table('payments')->where('order_id', $this->orderRow->id)->update(['expires_at' => now()->subMinute()]);

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(1);

        $o = Order::findOrFail($this->orderRow->id);
        expect($o->status->value)->toBe('cancelled')->and($o->cancel_reason)->toBe('Payment not completed in time')
            ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(0)
            ->and(DB::table('stock_reservations')->where('owner_ref', $o->public_id)->pluck('status')->unique()->all())->toBe(['released']);
        $this->get(URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'en', 'order' => $o->public_id]))->assertSee('This order was cancelled')->assertDontSee('Pay now');
    });

    it('does not cancel an order whose customer did pay just before the limit', function () {
        DB::table('payments')->where('order_id', $this->orderRow->id)->update(['expires_at' => now()->subMinute()]);
        $this->gateway->answer($this->ref, ($this->paidAnswer)($this->ref, 136000));

        expect(app(ExpireUnpaidPayments::class)->handle())->toBe(0);

        expect(($this->state)()->payment_status)->toBe('paid')->and(($this->state)()->status)->toBe('confirmed');
    });

    it('holds a mismatched payment for a person: the order is flagged, noted, and cannot be confirmed', function () {
        $this->gateway->answer($this->ref, ($this->paidAnswer)($this->ref, 100000));

        $r = $this->get(($this->returnUrl)())->assertRedirect();

        expect(($this->state)()->needs_review)->toBe(1)->and(($this->state)()->status)->toBe('pending')->and(($this->state)()->payment_status)->toBe('pending')
            ->and(DB::table('order_notes')->where('order_id', $this->orderRow->id)->value('body'))->toContain('Payment needs review: amount_mismatch');
        expect(fn () => app(TransitionOrder::class)->status(Actor::system('staff'), $this->orderRow->id, OrderStatus::Confirmed))->toThrow(OrderException::class, 'review_pending');
        $this->get($r->headers->get('Location'))->assertSee('need to check it');
    });

    it('does not reopen a cancelled order when money turns up late: the payment goes to review', function () {
        DB::table('payments')->where('order_id', $this->orderRow->id)->update(['expires_at' => now()->subMinute()]);
        app(ExpireUnpaidPayments::class)->handle();
        $this->gateway->answer($this->ref, ($this->paidAnswer)($this->ref, 136000));

        $this->get(($this->returnUrl)())->assertRedirect();

        expect(($this->state)()->status)->toBe('cancelled')->and(($this->state)()->payment_status)->toBe('pending')
            ->and(DB::table('payments')->where('order_id', $this->orderRow->id)->value('status'))->toBe('needs_review')->and(DB::table('reconciliation_exceptions')->pluck('type')->all())->toBe(['late_success']);
    });

    it('applies each announcement once even if the outbox delivers it twice', function () {
        $this->gateway->answer($this->ref, ($this->paidAnswer)($this->ref, 136000));
        $this->get(($this->returnUrl)());
        $before = DB::table('order_status_history')->where('order_id', $this->orderRow->id)->count();

        $outbox = DB::table('outbox_messages')->where('event_name', 'payments.succeeded')->first();
        $event = PaymentSucceeded::fromPayload(json_decode($outbox->payload, true));
        $event->outboxId = (int) $outbox->id;
        app(ApplyPaymentEvent::class)->succeeded($event);
        app(ApplyPaymentEvent::class)->succeeded($event);

        expect(DB::table('order_status_history')->where('order_id', $this->orderRow->id)->count())->toBe($before);
    });

    it('shows a "pay now" link that works for an order whose payment never started', function () {
        // an order placed online whose payment start was never reached
        fakeOrders([900], ['payment_method_code' => 'online', 'grand_total_minor' => 50000, 'subtotal_minor' => 50000]);
        $public = (string) DB::table('orders')->where('id', 900)->value('public_id');

        $page = $this->get(URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'en', 'order' => $public]))->assertOk()->assertSee('Pay now');
        preg_match('#href="([^"]*/pay/order/[^"]+)"#', html_entity_decode($page->getContent()), $m);
        $this->get($m[1])->assertRedirect('https://fake-gateway.test/pay/VM-TEST-000900-1');
    });
});

describe('orders nobody confirmed', function () {
    beforeEach(function () {
        config(['services.sms.driver' => 'fake']);
        $this->sms = app(FakeSmsGateway::class);
        ($this->cart)($this->tee, 2);
        ($this->as)()->post('/en/checkout/place', ($this->review)() + ['payment' => 'cod'])->assertRedirect();
        $this->o = ($this->order)();
        $this->sms->sent();   // the confirmation SMS is not what we are looking at
        $this->smsBefore = count($this->sms->sent());
    });

    it('are cancelled after three days, the stock goes back and the customer is told', function () {
        $this->travel(3 * 24 * 60 + 1)->minutes();

        expect(app(CancelStalePendingOrders::class)->handle())->toBe(1);

        $o = Order::findOrFail($this->o->id);
        expect($o->status->value)->toBe('cancelled')->and($o->cancel_reason)->toBe('Not confirmed within 3 days')->and((int) DB::table('stock_levels')->where('variant_id', $this->tee)->value('reserved'))->toBe(0)
            ->and(count($this->sms->sent()))->toBe($this->smsBefore + 1)->and(collect($this->sms->sent())->last()['message'])->toContain($o->order_number)->toContain('cancelled')
            ->and(app(CancelStalePendingOrders::class)->handle())->toBe(0);   // and not again
    });

    it('are left alone before the time, when turned off, when flagged, and when confirmed or paid', function () {
        $this->travel(2)->days();
        expect(app(CancelStalePendingOrders::class)->handle())->toBe(0);

        $this->travel(2)->days();   // now 4 days
        app(Settings::class)->set('orders.pending_cancel_days', 0);
        expect(app(CancelStalePendingOrders::class)->handle())->toBe(0);

        app(Settings::class)->set('orders.pending_cancel_days', 3);
        DB::table('orders')->where('id', $this->o->id)->update(['needs_review' => true]);
        expect(app(CancelStalePendingOrders::class)->handle())->toBe(0);

        DB::table('orders')->where('id', $this->o->id)->update(['needs_review' => false]);
        app(TransitionOrder::class)->status(Actor::system('staff'), $this->o->id, OrderStatus::Confirmed);
        expect(app(CancelStalePendingOrders::class)->handle())->toBe(0)->and(Order::findOrFail($this->o->id)->status->value)->toBe('confirmed');
    });

    it('writes the customer\'s message in the language the order was placed in', function () {
        ($this->cart)($this->tee, 1);
        ($this->as)()->post('/bn/checkout', ($this->physicalForm)())->assertRedirect();
        $html = ($this->as)()->get('/bn/checkout/review')->getContent();
        preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);
        ($this->as)()->post('/bn/checkout/place', ['place_token' => $t[1], 'expected_total' => $e[1], 'shipping' => 'standard', 'payment' => 'cod'])->assertRedirect();
        $this->travel(4)->days();

        app(CancelStalePendingOrders::class)->handle();

        expect(collect($this->sms->sent())->contains(fn ($m) => str_contains($m['message'], 'বাতিল')))->toBeTrue();
    });
});
