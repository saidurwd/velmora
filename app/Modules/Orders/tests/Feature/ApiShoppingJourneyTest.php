<?php

declare(strict_types=1);

use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\DigitalProducts\Database\Seeders\RefundPolicySeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Phase 11 exit gate: a mobile developer can build the whole shopping journey from the API document alone. This test is that developer. It starts with nothing, uses only
 * calls the document lists, takes every value it sends from an earlier answer (never from the database or from knowing the shop), and checks EVERY answer against the document.
 */

beforeEach(function () {
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    Notification::fake();
    shopWorld($this);
    $this->seed([GeographySeeder::class, ShippingSeeder::class, RefundPolicySeeder::class]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    Artisan::call('catalog:reindex');
    $this->gateway = app(FakeGateway::class);
    User::factory()->verified()->create(['email' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);

    // the app: every call goes out clean (no guard memory from the one before) with the headers the document asks for
    $this->call = function (string $method, string $uri, array $body = [], array $headers = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));
        $res = test()->json($method, '/api/v1'.$uri, $body, $headers + ['X-App-Platform' => 'android', 'X-App-Version' => '1.0.0', 'Accept-Language' => 'en']);
        assertMatchesOpenApi($res, $method, '/api/v1'.$uri);

        return $res;
    };
});

it('lets a new app browse, fill a cart as a guest, sign in, pay online and follow the order, using only what the document tells it', function () {
    // 1. start up: the app's settings
    $config = ($this->call)('GET', '/config')->assertOk();
    expect($config->json('data'))->not->toBeEmpty();

    // 2. browse, pick a product, read it for the variant to buy
    $list = ($this->call)('GET', '/products')->assertOk();
    $slug = collect($list->json('data'))->firstWhere('type', 'physical')['slug'];   // something to deliver
    $product = ($this->call)('GET', '/products/'.$slug)->assertOk();
    $variant = $product->json('data.variant.id');
    $qty = $product->json('data.variant.min_qty');   // the smallest amount this product is sold in
    $price = $product->json('data.price.amount_minor');
    expect($variant)->toHaveLength(26)->and($price)->toBeGreaterThan(0);

    // 3. add to the cart as a guest: the answer hands over the cart's token
    $added = ($this->call)('POST', '/cart/items', ['variant' => $variant, 'quantity' => $qty])->assertSuccessful();
    $cartToken = $added->json('data.token');
    expect($cartToken)->toBeString()->and($added->headers->get('X-Cart-Token'))->toBe($cartToken);
    $cart = ($this->call)('GET', '/cart', [], ['X-Cart-Token' => $cartToken])->assertOk();
    expect($cart->json('data.lines'))->toHaveCount(1)->and($cart->json('data.lines.0.quantity'))->toBe($qty);

    // 4. sign in, and bring the guest cart along
    $login = ($this->call)('POST', '/auth/login', ['identifier' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses', 'device_name' => 'Pixel 8'])->assertOk();
    $bearer = ['Authorization' => 'Bearer '.$login->json('data.access_token')];
    ($this->call)('POST', '/cart/merge', [], $bearer + ['X-Cart-Token' => $cartToken])->assertOk();
    ($this->call)('POST', '/devices', ['push_token' => str_repeat('fcm-token-', 12), 'platform' => 'android', 'app_version' => '1.0.0'], $bearer)->assertSuccessful();

    // 5. the address ladder, then the quote
    $division = ($this->call)('GET', '/geo/divisions')->assertOk()->json('data.0.id');
    $district = ($this->call)('GET', '/geo/districts?division='.$division)->assertOk()->json('data.0.id');
    $upazila = ($this->call)('GET', '/geo/upazilas?district='.$district)->assertOk()->json('data.0.id');
    $checkout = ['address' => ['recipient' => 'Amina Rahman', 'phone' => '01712345678', 'division_id' => $division, 'district_id' => $district, 'upazila_id' => $upazila, 'address_line' => 'House 5, Road 2'], 'shipping_method' => 'standard', 'payment_method' => 'online'];
    $quote = ($this->call)('POST', '/checkout/quote', $checkout, $bearer)->assertOk();
    $total = $quote->json('data.totals.grand_total.amount_minor');
    expect($total)->toBeGreaterThanOrEqual($price * $qty)->and(collect($quote->json('data.payment.options'))->pluck('code')->all())->toContain('online');

    // 6. place the order with a key of the app's own making; sending it again changes nothing
    $key = 'journey-'.bin2hex(random_bytes(6));
    $placed = ($this->call)('POST', '/checkout/orders', $checkout + ['expected_total' => $total], $bearer + ['Idempotency-Key' => $key])->assertCreated();
    $orderId = $placed->json('data.order.id');
    expect($placed->json('data.next'))->toBe('pay')->and($placed->json('data.order.payment_status'))->toBe('unpaid');
    $again = ($this->call)('POST', '/checkout/orders', $checkout + ['expected_total' => $total], $bearer + ['Idempotency-Key' => $key]);
    expect($again->headers->get('Idempotency-Replayed'))->toBe('true')->and(Order::count())->toBe(1);

    // 7. pay: the answer says where to send the person; once the gateway has the money, ask the server — it asks the gateway
    $pay = ($this->call)('POST', "/orders/{$orderId}/payments", [], $bearer + ['Idempotency-Key' => 'pay-'.bin2hex(random_bytes(6))])->assertOk();
    expect($pay->json('data.redirect_url'))->toBeString();
    $ref = (string) DB::table('payment_transactions')->orderByDesc('id')->value('merchant_ref');
    $unpaid = ($this->call)('POST', "/orders/{$orderId}/payment/verify", [], $bearer)->assertOk();
    expect($unpaid->json('data.paid'))->toBeFalse();
    $this->gateway->answer($ref, new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of($total), 'BANK-'.$ref, 'VAL-'.$ref));
    $paid = ($this->call)('POST', "/orders/{$orderId}/payment/verify", [], $bearer)->assertOk();
    expect($paid->json('data.paid'))->toBeTrue();

    // 8. follow it: the order, the list of orders, and where the parcel is
    $order = ($this->call)('GET', "/orders/{$orderId}", [], $bearer)->assertOk();
    expect($order->json('data.payment_status'))->toBe('paid')->and($order->json('data.totals.grand_total.amount_minor'))->toBe($total);
    expect(collect(($this->call)('GET', '/orders', [], $bearer)->assertOk()->json('data'))->pluck('id')->all())->toContain($orderId);
    ($this->call)('GET', "/orders/{$orderId}/tracking", [], $bearer)->assertOk();

    // 9. sign out
    ($this->call)('POST', '/auth/logout', [], $bearer)->assertNoContent();
    expect(DB::table('devices')->count())->toBe(0);
})->group('api-contract');

it('lets a guest do the same without ever signing in: the order token is the proof', function () {
    $slug = collect(($this->call)('GET', '/products')->assertOk()->json('data'))->firstWhere('type', 'physical')['slug'];
    $detail = ($this->call)('GET', '/products/'.$slug)->assertOk();
    $variant = $detail->json('data.variant.id');
    $cartToken = ($this->call)('POST', '/cart/items', ['variant' => $variant, 'quantity' => $detail->json('data.variant.min_qty')])->assertSuccessful()->json('data.token');
    $headers = ['X-Cart-Token' => $cartToken];
    $division = ($this->call)('GET', '/geo/divisions')->json('data.0.id');
    $district = ($this->call)('GET', '/geo/districts?division='.$division)->json('data.0.id');
    $upazila = ($this->call)('GET', '/geo/upazilas?district='.$district)->json('data.0.id');
    $checkout = ['address' => ['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $division, 'district_id' => $district, 'upazila_id' => $upazila, 'address_line' => 'House 5, Road 2'], 'contact' => ['email' => 'rahim@example.com'], 'shipping_method' => 'standard', 'payment_method' => 'cod'];

    $total = ($this->call)('POST', '/checkout/quote', $checkout, $headers)->assertOk()->json('data.totals.grand_total.amount_minor');
    $placed = ($this->call)('POST', '/checkout/orders', $checkout + ['expected_total' => $total], $headers + ['Idempotency-Key' => 'guest-'.bin2hex(random_bytes(6))])->assertCreated();
    $proof = ['X-Order-Token' => $placed->json('data.order_token')];

    expect($placed->json('data.next'))->toBe('none')->and($proof['X-Order-Token'])->toBeString();
    ($this->call)('GET', '/orders/'.$placed->json('data.order.id'), [], $proof)->assertOk()->assertJsonPath('data.number', $placed->json('data.order.number'));
    ($this->call)('GET', '/orders/'.$placed->json('data.order.id').'/tracking', [], $proof)->assertOk();
})->group('api-contract');
