<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Actions\ApplyCoupon;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Cms\Actions\AssignPolicyKind;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Enums\PolicyKind;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\DigitalProducts\Database\Seeders\RefundPolicySeeder;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Support\Settings;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/* Phase 11, slice 4: checkout for the app. Money and stock: priced by the server, placed once, never oversold, and the website and the app agree. */

beforeEach(function () {
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    Notification::fake();
    shopWorld($this);
    $this->seed([GeographySeeder::class, ShippingSeeder::class, RefundPolicySeeder::class]);

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
    $this->address = fn (array $over = []) => array_replace(['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2', 'area' => 'Dhanmondi', 'postal_code' => '1205'], $over);
    $this->body = fn (array $over = []) => array_replace_recursive(['address' => ($this->address)(), 'contact' => ['email' => 'rahim@example.com'], 'shipping_method' => 'standard', 'payment_method' => 'cod'], $over);

    // a guest with something in the cart
    $this->guest = function (array $lines = [], ?string $token = null): string {
        $token ??= bin2hex(random_bytes(20));
        foreach ($lines ?: [[$this->tee, 2]] as [$v, $q]) {
            app(AddToCart::class)->handle(CartOwner::guest($token), $v['variantId'], $q);
        }

        return $token;
    };
    $this->asGuest = function (string $token, array $extra = []) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['X-Cart-Token' => $token] + $extra;
    };
    $this->customer = User::factory()->verified()->create(['email' => 'amina@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);
    $this->asUser = function (?User $u = null, array $extra = []) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u ?? $this->customer, 'Pixel', 'android', 'password')->accessToken] + $extra;
    };
    $this->key = fn () => 'key-'.bin2hex(random_bytes(8));
    $this->quote = fn (array $headers, array $over = []) => $this->postJson('/api/v1/checkout/quote', ($this->body)($over), $headers);
    $this->place = fn (array $headers, int $total, array $over = [], ?string $key = null) => $this->postJson('/api/v1/checkout/orders', ($this->body)(['expected_total' => $total] + $over), $headers + ['Idempotency-Key' => $key ?? ($this->key)()]);
    $this->set = fn (string $key, mixed $value) => app(Settings::class)->set($key, $value);
});

describe('pricing the checkout', function () {
    it('prices a guest cart: every part, delivery options, ways to pay, and a total that adds up', function () {
        $token = ($this->guest)();
        $res = ($this->quote)(($this->asGuest)($token));

        $res->assertOk()->assertJsonPath('data.totals.grand_total.amount_minor', 136000)->assertJsonPath('data.totals.subtotal.amount_minor', 130000)->assertJsonPath('data.totals.shipping.amount_minor', 6000)
            ->assertJsonPath('data.delivery.selected', 'standard')->assertJsonPath('data.payment.selected', 'cod')->assertJsonPath('data.address.upazila', fn ($v) => is_string($v))->assertJsonPath('data.contact.phone', '+8801712345678');
        assertMatchesOpenApi($res, 'POST', '/api/v1/checkout/quote');
        $t = $res->json('data.totals');
        expect($t['subtotal']['amount_minor'] - $t['discount']['amount_minor'] + $t['shipping']['amount_minor'] + $t['cod_fee']['amount_minor'] + ($t['tax_inclusive'] ? 0 : $t['tax']['amount_minor']))->toBe($t['grand_total']['amount_minor'])
            ->and(collect($res->json('data.delivery.options'))->pluck('code')->all())->toContain('standard')->and(collect($res->json('data.payment.options'))->pluck('code')->all())->toContain('cod');
        expect(Order::count())->toBe(0)->and(DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBe(0);
    });

    it('prices a cart with nothing to deliver from a name and phone, with no delivery and no cash on delivery', function () {
        $token = ($this->guest)([[$this->ebook, 1]]);
        $res = $this->postJson('/api/v1/checkout/quote', ['contact' => ['name' => 'Rahim', 'phone' => '01712345678'], 'payment_method' => 'cod'], ($this->asGuest)($token));

        $res->assertOk()->assertJsonPath('data.delivery.required', false)->assertJsonPath('data.address', null)->assertJsonPath('data.totals.shipping.amount_minor', 0);
        expect(collect($res->json('data.payment.options'))->pluck('code')->all())->not->toContain('cod');
        $this->postJson('/api/v1/checkout/quote', ['contact' => ['name' => '', 'phone' => '01712345678']], ($this->asGuest)($token))->assertStatus(422)->assertJsonPath('code', 'validation.failed');
    });

    it('refuses what cannot be priced, with a code for each reason', function () {
        $empty = bin2hex(random_bytes(20));
        ($this->quote)(($this->asGuest)($empty))->assertStatus(422)->assertJsonPath('code', 'cart.empty');

        $token = ($this->guest)();
        ($this->setStock)($this->tee, 1);
        ($this->quote)(($this->asGuest)($token))->assertStatus(409)->assertJsonPath('code', 'cart.problem');
        ($this->setStock)($this->tee, 10);

        $this->postJson('/api/v1/checkout/quote', ['shipping_method' => 'standard'], ($this->asGuest)($token))->assertStatus(422)->assertJsonValidationErrors(['recipient']);
        ($this->quote)(($this->asGuest)($token), ['address' => ['phone' => 'banana']])->assertStatus(422)->assertJsonValidationErrors(['address.phone']);
        ($this->quote)(($this->asGuest)($token), ['address' => ['upazila_id' => 999999]])->assertStatus(422)->assertJsonValidationErrors(['address.upazila_id']);
    });

    it('uses a saved address for a signed-in customer, and never one that belongs to somebody else', function () {
        $mine = app(AddressBook::class)->save($this->customer->id, null, AddressInput::fromArray(($this->address)()));
        $other = User::factory()->verified()->create();
        $theirs = app(AddressBook::class)->save($other->id, null, AddressInput::fromArray(($this->address)(['recipient' => 'Somebody Else', 'phone' => '01812345678'])));
        app(AddToCart::class)->handle(CartOwner::user($this->customer->id), $this->tee['variantId'], 1);

        $ok = $this->postJson('/api/v1/checkout/quote', ['address' => ['id' => $mine->publicId], 'payment_method' => 'cod'], ($this->asUser)());
        $ok->assertOk()->assertJsonPath('data.address.id', $mine->publicId)->assertJsonPath('data.contact.name', 'Rahim Uddin');

        $no = $this->postJson('/api/v1/checkout/quote', ['address' => ['id' => $theirs->publicId], 'payment_method' => 'cod'], ($this->asUser)());
        $no->assertStatus(422)->assertJsonPath('code', 'address.required');
        expect(json_encode($no->json()))->not->toContain('Somebody Else');
    });

    it('answers in Bangla when asked, with the same figures', function () {
        $token = ($this->guest)();
        $en = ($this->quote)(($this->asGuest)($token))->json('data.totals');
        $bn = ($this->quote)(($this->asGuest)($token, ['Accept-Language' => 'bn']));

        $bn->assertOk();
        expect($bn->json('data.totals.grand_total.amount_minor'))->toBe($en['grand_total']['amount_minor'])->and($bn->json('data.cart.lines.0.name'))->toBe('সুতির টি-শার্ট');
    });
});

describe('placing the order', function () {
    it('makes one order from a guest cart, reserves the stock, empties the cart and says what comes next', function () {
        $token = ($this->guest)();
        $res = ($this->place)(($this->asGuest)($token), 136000);

        $res->assertCreated()->assertJsonPath('data.next', 'none')->assertJsonPath('data.order.totals.grand_total.amount_minor', 136000)->assertJsonPath('data.order.payment_method', 'cod');
        assertMatchesOpenApi($res, 'POST', '/api/v1/checkout/orders');

        $order = Order::firstOrFail();
        expect($order->public_id)->toBe($res->json('data.order.id'))->and($order->channel->value)->toBe('api')->and($order->contact_phone)->toBe('+8801712345678')->and($order->user_id)->toBeNull()
            ->and($order->grand_total_minor)->toBe(136000)->and((int) DB::table('stock_reservations')->where('owner_type', 'order')->where('owner_ref', $order->public_id)->sum('quantity'))->toBe(2);
        $this->getJson('/api/v1/cart', ($this->asGuest)($token))->assertOk()->assertJsonPath('data.item_count', 0);
        expect(DB::table('outbox_messages')->where('event_name', 'orders.placed')->count())->toBe(1);
    });

    it('attaches the order to a signed-in customer and uses their saved address', function () {
        $mine = app(AddressBook::class)->save($this->customer->id, null, AddressInput::fromArray(($this->address)()));
        app(AddToCart::class)->handle(CartOwner::user($this->customer->id), $this->tee['variantId'], 1);

        $res = $this->postJson('/api/v1/checkout/orders', ['address' => ['id' => $mine->publicId], 'payment_method' => 'cod', 'shipping_method' => 'standard', 'expected_total' => 71000], ($this->asUser)() + ['Idempotency-Key' => ($this->key)()]);

        $res->assertCreated();
        expect(Order::firstOrFail()->user_id)->toBe($this->customer->id)->and(DB::table('cart_items')->count())->toBe(0);
    });

    it('does not place an order when the total moved: 409 with the fresh quote, nothing reserved', function () {
        $token = ($this->guest)();
        $res = ($this->place)(($this->asGuest)($token), 100000);

        $res->assertStatus(409)->assertJsonPath('code', 'checkout.totals_changed')->assertJsonPath('quote.totals.grand_total.amount_minor', 136000);
        expect(Order::count())->toBe(0)->and(DB::table('stock_reservations')->count())->toBe(0)->and(DB::table('cart_items')->count())->toBe(1);

        ($this->setPrice)($this->tee, 700);
        ($this->place)(($this->asGuest)($token), 136000)->assertStatus(409)->assertJsonPath('code', 'checkout.totals_changed')->assertJsonPath('quote.totals.grand_total.amount_minor', 146000);
        expect(Order::count())->toBe(0);
    });

    it('needs the Idempotency-Key, and refuses a malformed one', function () {
        $token = ($this->guest)();
        $h = ($this->asGuest)($token);

        $this->postJson('/api/v1/checkout/orders', ($this->body)(['expected_total' => 136000]), $h)->assertStatus(400)->assertJsonPath('code', 'idempotency.key_required');
        $this->postJson('/api/v1/checkout/orders', ($this->body)(['expected_total' => 136000]), $h + ['Idempotency-Key' => 'short'])->assertStatus(400)->assertJsonPath('code', 'idempotency.key_invalid');
        expect(Order::count())->toBe(0);
    });

    it('makes the same order, once, however many times the same key is sent, even after the replay window', function () {
        $token = ($this->guest)();
        $key = ($this->key)();
        $first = ($this->place)(($this->asGuest)($token), 136000, [], $key);
        $again = ($this->place)(($this->asGuest)($token), 136000, [], $key);

        $first->assertCreated();
        $again->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.order.id', $first->json('data.order.id'));
        expect(Order::count())->toBe(1);

        DB::table('idempotency_keys')->delete(); // the 24-hour replay record is gone; the order's own key is permanent
        app(AddToCart::class)->handle(CartOwner::guest($token), $this->tee['variantId'], 2);
        $late = ($this->place)(($this->asGuest)($token), 136000, [], $key);
        expect(Order::count())->toBe(1)->and($late->json('data.order.id'))->toBe($first->json('data.order.id'));
    });

    it('refuses the same key with a different order, and keeps two guests’ keys apart even from one address', function () {
        $a = ($this->guest)();
        $b = ($this->guest)([[$this->lamp, 2]]);
        $key = ($this->key)();
        $first = ($this->place)(($this->asGuest)($a), 136000, [], $key)->assertCreated();

        ($this->place)(($this->asGuest)($a), 136000, ['shipping_method' => 'express'], $key)->assertStatus(409)->assertJsonPath('code', 'idempotency.conflict');

        $other = ($this->place)(($this->asGuest)($b), 136000, [], $key);
        expect($other->json('data.order.id'))->not->toBe($first->json('data.order.id'))->and(json_encode($other->json()))->not->toContain((string) $first->json('data.order.number'));
    });

    it('never oversells the last unit: the second buyer is told, and stock never goes below zero', function () {
        ($this->setStock)($this->tee, 1);
        $a = ($this->guest)([[$this->tee, 1]]);
        $b = ($this->guest)([[$this->tee, 1]]);

        ($this->place)(($this->asGuest)($a), 71000)->assertCreated();
        $second = ($this->place)(($this->asGuest)($b), 71000);

        $second->assertStatus(409);
        expect($second->json('code'))->toBeIn(['cart.problem', 'stock.insufficient'])->and(Order::count())->toBe(1)
            ->and((int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('on_hand') - (int) DB::table('stock_levels')->where('variant_id', $this->tee['variantId'])->value('reserved'))->toBeGreaterThanOrEqual(0);
    });

    it('does not let one guest place another’s cart: a different token is a different, empty cart', function () {
        $a = ($this->guest)();
        $stranger = bin2hex(random_bytes(20));

        ($this->place)(($this->asGuest)($stranger), 136000)->assertStatus(422)->assertJsonPath('code', 'cart.empty');
        expect(Order::count())->toBe(0)->and(DB::table('cart_items')->count())->toBe(1);
        ($this->place)(($this->asGuest)($a), 136000)->assertCreated();
    });

    it('rejects a bad bearer token instead of placing the order as a guest', function () {
        $token = ($this->guest)();

        $this->postJson('/api/v1/checkout/orders', ($this->body)(['expected_total' => 136000]), ['Authorization' => 'Bearer 1|nonsense', 'X-Cart-Token' => $token, 'Idempotency-Key' => ($this->key)()])->assertStatus(401);
        expect(Order::count())->toBe(0);
    });
});

describe('cash on delivery rules', function () {
    it('limits the open cash orders of one phone number', function () {
        ($this->set)('fraud.max_open_cod_per_phone', 2);
        foreach ([1, 2] as $_) {
            ($this->place)(($this->asGuest)(($this->guest)([[$this->tee, 1]])), 71000)->assertCreated();
        }

        $third = ($this->place)(($this->asGuest)(($this->guest)([[$this->tee, 1]])), 71000);
        $third->assertStatus(422)->assertJsonPath('code', 'cod.not_eligible')->assertJsonPath('params.max', 2);
        expect(Order::count())->toBe(2);
    });

    it('wants the phone confirmed for a large cash order, and accepts a proof for that phone only, once it is fresh', function () {
        ($this->set)('fraud.high_value_cod_minor', 100_000);
        $sms = app(FakeSmsGateway::class);
        $token = ($this->guest)();

        $quote = ($this->quote)(($this->asGuest)($token));
        $quote->assertJsonPath('data.requires_phone_confirmation', true);
        ($this->place)(($this->asGuest)($token), 136000)->assertStatus(403)->assertJsonPath('code', 'checkout.phone_confirmation_required');
        expect(Order::count())->toBe(0);

        $this->postJson('/api/v1/checkout/phone-code', ['phone' => '01712345678'], ($this->asGuest)($token))->assertStatus(202)->assertJsonPath('data.status', 'sent');
        $this->postJson('/api/v1/checkout/phone-confirm', ['phone' => '01712345678', 'code' => '000000'], ($this->asGuest)($token))->assertStatus(401)->assertJsonPath('code', 'auth.otp_invalid');
        $ok = $this->postJson('/api/v1/checkout/phone-confirm', ['phone' => '01712345678', 'code' => (string) $sms->lastCode()], ($this->asGuest)($token));
        $ok->assertOk()->assertJsonPath('data.expires_in', 1800);
        assertMatchesOpenApi($ok, 'POST', '/api/v1/checkout/phone-confirm');
        $proof = $ok->json('data.phone_proof');

        // a proof for another number, a damaged proof and an old proof do not count
        $other = $this->postJson('/api/v1/checkout/phone-code', ['phone' => '01812345678'], ($this->asGuest)($token));
        $otherProof = $this->postJson('/api/v1/checkout/phone-confirm', ['phone' => '01812345678', 'code' => (string) $sms->lastCode()], ($this->asGuest)($token))->json('data.phone_proof');
        ($this->place)(($this->asGuest)($token), 136000, ['phone_proof' => $otherProof])->assertStatus(403);
        ($this->place)(($this->asGuest)($token), 136000, ['phone_proof' => substr($proof, 0, -3).'abc'])->assertStatus(403);
        ($this->place)(($this->asGuest)($token), 136000, ['phone_proof' => 'garbage'])->assertStatus(403);
        $this->travel(31)->minutes();
        ($this->place)(($this->asGuest)($token), 136000, ['phone_proof' => $proof])->assertStatus(403);
        $this->travelBack();

        ($this->place)(($this->asGuest)($token), 136000, ['phone_proof' => $proof])->assertCreated();
        expect($other->status())->toBe(202)->and(Order::count())->toBe(1);
    });

    it('limits how often a code can be asked for, and checks the phone number it is asked for', function () {
        $this->postJson('/api/v1/checkout/phone-code', ['phone' => 'banana'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->postJson('/api/v1/checkout/phone-confirm', ['phone' => '01712345678'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/checkout/phone-code', ['phone' => '01712345678'])->assertStatus(202);
        $this->postJson('/api/v1/checkout/phone-code', ['phone' => '01712345678'])->assertStatus(202)->assertJsonPath('data.status', 'cooling_down');
    });
});

describe('policies', function () {
    beforeEach(function () {
        $this->release = function (string $kind) {
            $page = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => ucfirst($kind), 'slug' => $kind, 'body' => '<p>text</p>']]);
            app(AssignPolicyKind::class)->handle($this->actor, $page, PolicyKind::from($kind));
            app(TransitionPage::class)->publish($this->actor, $page, 1);
        };
    });

    it('lists the policies to accept and refuses an order until they are accepted exactly as shown', function () {
        ($this->release)('terms');
        $token = ($this->guest)();

        $quote = ($this->quote)(($this->asGuest)($token));
        $quote->assertOk();
        $ids = collect($quote->json('data.policies'))->pluck('release_id')->all();
        expect($ids)->not->toBeEmpty();

        ($this->place)(($this->asGuest)($token), 136000)->assertStatus(422)->assertJsonPath('code', 'policies.required');
        ($this->place)(($this->asGuest)($token), 136000, ['accept_policies' => true, 'policy_releases' => [999999]])->assertStatus(409)->assertJsonPath('code', 'policies.changed');
        expect(Order::count())->toBe(0);

        ($this->place)(($this->asGuest)($token), 136000, ['accept_policies' => true, 'policy_releases' => $ids])->assertCreated();
        expect(DB::table('policy_acceptances')->count())->toBeGreaterThan(0);
    });
});

describe('the website and the app agree', function () {
    it('make the same order from the same cart: same lines, same figures, same events', function () {
        $api = bin2hex(random_bytes(20));
        $web = str_repeat('e', 40);
        foreach ([$api, $web] as $t) {
            app(AddToCart::class)->handle(CartOwner::guest($t), $this->tee['variantId'], 2);
            app(AddToCart::class)->handle(CartOwner::guest($t), $this->lamp['variantId'], 2);
        }
        DB::table('coupons')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => 'SAVE10', 'type' => 'percent', 'value' => 1000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$api, $web] as $t) {
            app(ApplyCoupon::class)->handle(CartOwner::guest($t), 'SAVE10', 'en');
        }

        $quote = ($this->quote)(($this->asGuest)($api))->assertOk()->json('data.totals.grand_total.amount_minor');
        ($this->place)(($this->asGuest)($api), $quote)->assertCreated();

        $form = ['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2', 'area' => 'Dhanmondi', 'postal_code' => '1205', 'email' => 'rahim@example.com'];
        $this->withCookie(GuestCartToken::COOKIE, $web)->post('/en/checkout', $form)->assertRedirect('/en/checkout/review');
        $html = $this->withCookie(GuestCartToken::COOKIE, $web)->get('/en/checkout/review')->assertOk()->getContent();
        preg_match('/name="expected_total" value="(\d+)"/', (string) $html, $shown);
        expect((int) ($shown[1] ?? 0))->toBe($quote);
        $this->withCookie(GuestCartToken::COOKIE, $web)->post('/en/checkout/place', ['place_token' => str_repeat('a', 40), 'expected_total' => (string) $quote, 'shipping' => 'standard', 'payment' => 'cod'])->assertSessionHasNoErrors()->assertRedirect();

        expect(Order::query()->get()->map(fn ($o) => $o->channel->value)->all())->toBe(['api', 'web']);
        [$apiOrder, $webOrder] = [Order::query()->where('channel', 'api')->firstOrFail(), Order::query()->where('channel', 'web')->firstOrFail()];
        $figures = fn (Order $o) => $o->only(['subtotal_minor', 'discount_minor', 'shipping_minor', 'cod_fee_minor', 'tax_minor', 'grand_total_minor', 'tax_inclusive', 'payment_method_code', 'shipping_method_code', 'status', 'payment_status', 'fulfillment_status', 'currency']);
        $lines = fn (Order $o) => DB::table('order_items')->where('order_id', $o->id)->orderBy('variant_id')->get(['variant_id', 'quantity', 'unit_price_minor', 'discount_minor', 'line_total_minor', 'tax_minor'])->map(fn ($r) => (array) $r)->all();

        expect($figures($apiOrder))->toEqual($figures($webOrder))->and($lines($apiOrder))->toBe($lines($webOrder))->and($apiOrder->grand_total_minor)->toBe($quote)->and($apiOrder->discount_minor)->toBeGreaterThan(0)
            ->and(DB::table('outbox_messages')->where('event_name', 'orders.placed')->count())->toBe(2)
            ->and(DB::table('stock_reservations')->where('owner_ref', $apiOrder->public_id)->sum('quantity'))->toBe(DB::table('stock_reservations')->where('owner_ref', $webOrder->public_id)->sum('quantity'))
            ->and(DB::table('coupons')->where('code', 'SAVE10')->value('used_count'))->toBe(2);
    });
});
