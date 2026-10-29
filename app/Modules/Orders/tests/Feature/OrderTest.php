<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\DTOs\PriceBreakdown;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'General')]);

    $this->variant = function (string $sku, string $en = 'Item', string $bn = 'পণ্য') use ($actor, $category): int {
        $p = app(SaveProduct::class)->handle($actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', $en), new TranslationInput('bn', $bn)], categoryIds: [$category->id], defaultVariant: new VariantData($sku, trackInventory: false)));

        return (int) $p->variants()->value('id');
    };
    $this->tee = ($this->variant)('TEE-1', 'Cotton Tee', 'সুতির টি-শার্ট');
    $this->mug = ($this->variant)('MUG-1', 'Mug', 'মগ');

    $this->breakdown = fn (?array $lines = null, int $orderDiscount = 0, int $shipping = 6000, int $cod = 2000, bool $inclusive = false, int $vat = 0): PriceBreakdown => (new BreakdownCalculator)->calculate(
        $lines ?? [['variantId' => $this->tee, 'quantity' => 2, 'unitMinor' => 65000, 'lineDiscountMinor' => 0, 'taxRateBp' => $vat], ['variantId' => $this->mug, 'quantity' => 1, 'unitMinor' => 35050, 'lineDiscountMinor' => 1000, 'taxRateBp' => $vat]],
        $inclusive, $orderDiscount, $shipping, $cod,
    );
    $this->address = fn () => new NewOrderAddress('shipping', 'Rahim Uddin', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], 'House 5, Road 2', '1205', 'Dhanmondi', 'Near the lake');
    $this->newOrder = fn (?PriceBreakdown $b = null, array $over = []) => new NewOrder(
        Actor::guest('checkout'), $over['userId'] ?? null, 'Rahim Uddin', '+8801712345678', null, OrderChannel::Web, 'en', 'cod', 'standard', $b ?? ($this->breakdown)(),
        [new NewOrderLine($this->tee, 'physical', ['en' => 'Cotton Tee', 'bn' => 'সুতির টি-শার্ট'], 'TEE-1', ['Size' => 'M']), new NewOrderLine($this->mug, 'physical', ['en' => 'Mug', 'bn' => 'মগ'], 'MUG-1')],
        $over['addresses'] ?? [($this->address)()],
    );
    $this->place = fn (?PriceBreakdown $b = null, array $over = []) => app(CreateOrder::class)->handle(($this->newOrder)($b, $over));
    $this->staff = Actor::system('staff');
});

describe('placing an order record', function () {
    it('stores the order exactly as priced, with line, address and history snapshots', function () {
        $b = ($this->breakdown)();
        $order = ($this->place)($b);

        expect($order->order_number)->toMatch('/^VM-\d{4}-\d{6}$/')->and($order->public_id)->toHaveLength(26)
            ->and($order->status)->toBe(OrderStatus::Pending)->and($order->payment_status)->toBe(PaymentStatus::Unpaid)->and($order->fulfillment_status)->toBe(FulfillmentStatus::Unfulfilled)
            ->and($order->grand_total_minor)->toBe($b->grandTotalMinor)->and($order->subtotal_minor)->toBe(165050)->and($order->discount_minor)->toBe(1000)
            ->and($order->shipping_minor)->toBe(6000)->and($order->cod_fee_minor)->toBe(2000)->and($order->grand_total_minor)->toBe(165050 - 1000 + 6000 + 2000);

        $items = $order->items;
        expect($items)->toHaveCount(2)->and($items[0]->name('bn'))->toBe('সুতির টি-শার্ট')->and($items[0]->quantity)->toBe(2)->and($items[0]->line_total_minor)->toBe(130000)->and($items[0]->options_snapshot)->toBe(['Size' => 'M'])
            ->and($items[1]->discount_minor)->toBe(1000)->and($items[1]->line_total_minor)->toBe(34050)
            ->and($order->addresses)->toHaveCount(1)->and($order->addresses[0]->locationLine('bn'))->toBe('ধানমন্ডি, ঢাকা, ঢাকা')
            ->and($order->history->map(fn ($h) => $h->axis.':'.$h->to_state)->all())->toBe(['status:pending', 'payment:unpaid', 'fulfillment:unfulfilled']);
    });

    it('keeps the order as it was when the catalog changes afterwards', function () {
        $order = ($this->place)();
        DB::table('product_translations')->where('name', 'Cotton Tee')->update(['name' => 'Renamed']);
        DB::table('product_variants')->where('id', $this->tee)->update(['sku' => 'TEE-NEW']);

        expect($order->fresh()->items[0]->name('en'))->toBe('Cotton Tee')->and($order->fresh()->items[0]->sku_snapshot)->toBe('TEE-1');
    });

    it('splits an order discount into the lines and the stored line totals add up to the order', function () {
        $b = ($this->breakdown)(orderDiscount: 2501, shipping: 0, cod: 0);
        $order = ($this->place)($b);

        expect($order->items->sum('line_total_minor'))->toBe($order->subtotal_minor - $order->discount_minor)->and($order->discount_minor)->toBe(3501);
    });

    it('records tax: added on top, or included, and tax on delivery as its own line', function () {
        $added = ($this->place)(($this->breakdown)(inclusive: false, vat: 1500));
        expect($added->tax_inclusive)->toBeFalse()->and($added->tax_minor)->toBe($added->items->sum('tax_minor'))->and($added->grand_total_minor)->toBe($added->subtotal_minor - $added->discount_minor + 6000 + 2000 + $added->tax_minor);

        $included = ($this->place)(($this->breakdown)(inclusive: true, vat: 1500));
        expect($included->tax_inclusive)->toBeTrue()->and($included->tax_minor)->toBeGreaterThan(0)->and($included->grand_total_minor)->toBe($included->subtotal_minor - $included->discount_minor + 6000 + 2000);
    });

    it('refuses totals that do not add up, lines that do not match, and a physical order with no address', function () {
        $b = ($this->breakdown)();
        $broken = new PriceBreakdown($b->currency, $b->taxInclusive, $b->lines, $b->subtotalMinor, $b->lineDiscountMinor, $b->orderDiscountMinor, $b->shippingMinor, $b->codFeeMinor, $b->taxMinor, $b->grandTotalMinor + 1);

        expect(fn () => ($this->place)($broken))->toThrow(OrderException::class, 'breakdown_mismatch')
            ->and(fn () => ($this->place)($b, ['addresses' => []]))->toThrow(OrderException::class, 'address_required')
            ->and(fn () => app(CreateOrder::class)->handle(new NewOrder(Actor::guest(), null, 'A', '+8801712345678', null, OrderChannel::Web, 'en', 'cod', null, $b, [], [($this->address)()])))->toThrow(OrderException::class, 'lines_mismatch');
        expect(Order::count())->toBe(0);
    });

    it('writes nothing at all when a later step fails', function () {
        $new = ($this->newOrder)();
        $bad = new NewOrder($new->actor, null, 'A', '+8801712345678', null, OrderChannel::Web, 'en', 'cod', null, $new->breakdown,
            [new NewOrderLine($this->tee, 'physical', ['en' => 'x'], 'TEE-1'), new NewOrderLine($this->mug, 'bogus-type', ['en' => 'y'], 'MUG-1')], $new->addresses);

        expect(fn () => app(CreateOrder::class)->handle($bad))->toThrow(QueryException::class);
        expect(Order::count())->toBe(0)->and(DB::table('order_items')->count())->toBe(0)->and(DB::table('order_status_history')->count())->toBe(0);
    });

    it('gives every order its own number, with the month in Dhaka time', function () {
        $a = ($this->place)();
        $b = ($this->place)();

        expect($a->order_number)->not->toBe($b->order_number)->and((int) substr($b->order_number, -6))->toBe((int) substr($a->order_number, -6) + 1);
        // 1 Dec 2026 20:00 UTC is already 2 Dec 02:00 in Dhaka; 30 Nov 2026 17:59 UTC is 30 Nov 23:59 in Dhaka, 18:00 UTC is 1 Dec 00:00
        expect(OrderNumbers::format(7, CarbonImmutable::parse('2026-11-30 17:59:00', 'UTC')))->toBe('VM-2611-000007')->and(OrderNumbers::format(8, CarbonImmutable::parse('2026-11-30 18:00:00', 'UTC')))->toBe('VM-2612-000008');
    });
});

describe('the database refuses wrong or rewritten orders', function () {
    it('rejects an order whose parts do not add up', function () {
        $order = ($this->place)();

        expect(fn () => DB::table('orders')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_number' => 'VM-BAD-000001', 'contact_name' => 'x', 'contact_phone' => '+8801712345678', 'locale' => 'en',
            'subtotal_minor' => 100, 'discount_minor' => 0, 'shipping_minor' => 0, 'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 99, 'tax_inclusive' => false, 'payment_method_code' => 'cod', 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
        expect($order->id)->toBeInt();
    });

    it('freezes money and identity, lets only the state move, and never deletes', function () {
        $order = ($this->place)();

        foreach (['grand_total_minor' => 1, 'subtotal_minor' => 1, 'order_number' => 'VM-X', 'contact_phone' => '+8801800000000', 'placed_at' => now()->addDay()] as $col => $val) {
            expect(fn () => DB::table('orders')->where('id', $order->id)->update([$col => $val]))->toThrow(QueryException::class);
        }
        expect(fn () => DB::table('orders')->where('id', $order->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_items')->where('order_id', $order->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_items')->where('order_id', $order->id)->update(['unit_price_minor' => 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_status_history')->where('order_id', $order->id)->update(['to_state' => 'paid']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_status_history')->where('order_id', $order->id)->delete())->toThrow(QueryException::class);

        expect(DB::table('orders')->where('id', $order->id)->update(['needs_review' => true]))->toBe(1);
    });

    it('keeps shipped and returned quantities within what was ordered', function () {
        $order = ($this->place)();
        $item = $order->items[0];

        expect(fn () => DB::table('order_items')->where('id', $item->id)->update(['fulfilled_qty' => 3]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_items')->where('id', $item->id)->update(['returned_qty' => 1]))->toThrow(QueryException::class);
        DB::table('order_items')->where('id', $item->id)->update(['fulfilled_qty' => 2]);
        DB::table('order_items')->where('id', $item->id)->update(['returned_qty' => 1]);
        expect((int) DB::table('order_items')->where('id', $item->id)->value('returned_qty'))->toBe(1);
    });

    it('ties coupon redemptions to real orders', function () {
        $couponId = DB::table('coupons')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'X1', 'type' => 'fixed', 'value' => 1, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => DB::table('coupon_redemptions')->insert(['coupon_id' => $couponId, 'order_id' => 999999, 'redeemer' => 'u:1', 'amount_minor' => 1, 'redeemed_at' => now()]))->toThrow(QueryException::class);
    });
});

describe('moving an order along', function () {
    it('walks the happy path and writes who, when and why on every step', function () {
        $o = ($this->place)();
        $t = app(TransitionOrder::class);

        $t->status($this->staff, $o->id, OrderStatus::Confirmed);
        $t->status($this->staff, $o->id, OrderStatus::Processing);
        $t->fulfillment($this->staff, $o->id, FulfillmentStatus::Fulfilled, 'handed to courier');
        $t->payment($this->staff, $o->id, PaymentStatus::Paid, 'cash collected');
        $done = $t->status(new Actor(ActorType::Staff, 7), $o->id, OrderStatus::Completed);

        expect($done->status)->toBe(OrderStatus::Completed)->and($done->payment_status)->toBe(PaymentStatus::Paid);
        $rows = DB::table('order_status_history')->where('order_id', $o->id)->orderBy('id')->get(['axis', 'from_state', 'to_state', 'actor_type', 'actor_id', 'reason']);
        expect($rows)->toHaveCount(8)->and((array) $rows[3])->toBe(['axis' => 'status', 'from_state' => 'pending', 'to_state' => 'confirmed', 'actor_type' => 'system', 'actor_id' => null, 'reason' => null])
            ->and((array) $rows[6])->toBe(['axis' => 'payment', 'from_state' => 'unpaid', 'to_state' => 'paid', 'actor_type' => 'system', 'actor_id' => null, 'reason' => 'cash collected'])
            ->and($rows[7]->actor_id)->toBe(7);
    });

    it('refuses jumps, repeats and going backwards', function () {
        $o = ($this->place)();
        $t = app(TransitionOrder::class);

        expect(fn () => $t->status($this->staff, $o->id, OrderStatus::Completed))->toThrow(OrderException::class, 'illegal_transition')
            ->and(fn () => $t->status($this->staff, $o->id, OrderStatus::Pending))->toThrow(OrderException::class, 'already_in_state')
            ->and(fn () => $t->payment($this->staff, $o->id, PaymentStatus::Refunded))->toThrow(OrderException::class, 'illegal_transition')
            ->and(fn () => $t->fulfillment($this->staff, $o->id, FulfillmentStatus::Returned))->toThrow(OrderException::class, 'illegal_transition');
        $t->status($this->staff, $o->id, OrderStatus::Confirmed);
        expect(fn () => $t->status($this->staff, $o->id, OrderStatus::Pending))->toThrow(OrderException::class, 'illegal_transition');
        expect(DB::table('order_status_history')->where('order_id', $o->id)->count())->toBe(4);   // 3 at placing + 1 confirmation; the refusals wrote nothing
    });

    it('cancels with a reason, stamps the time, and then nothing moves', function () {
        $o = ($this->place)();
        $t = app(TransitionOrder::class);

        expect(fn () => $t->status($this->staff, $o->id, OrderStatus::Cancelled))->toThrow(OrderException::class, 'reason_required')
            ->and(fn () => $t->status($this->staff, $o->id, OrderStatus::Cancelled, '   '))->toThrow(OrderException::class, 'reason_required');

        $c = $t->status($this->staff, $o->id, OrderStatus::Cancelled, 'Customer called to cancel');
        expect($c->status)->toBe(OrderStatus::Cancelled)->and($c->cancelled_at)->not->toBeNull()->and($c->cancel_reason)->toBe('Customer called to cancel')
            ->and(fn () => $t->status($this->staff, $o->id, OrderStatus::Confirmed))->toThrow(OrderException::class, 'illegal_transition')
            ->and(fn () => $t->payment($this->staff, $o->id, PaymentStatus::Paid))->toThrow(OrderException::class, 'order_cancelled')
            ->and(fn () => $t->fulfillment($this->staff, $o->id, FulfillmentStatus::Fulfilled))->toThrow(OrderException::class, 'order_cancelled');
    });

    it('will not cancel what has shipped or been paid for', function () {
        $t = app(TransitionOrder::class);

        $shipped = ($this->place)();
        $t->status($this->staff, $shipped->id, OrderStatus::Confirmed);
        $t->fulfillment($this->staff, $shipped->id, FulfillmentStatus::PartiallyFulfilled);
        expect(fn () => $t->status($this->staff, $shipped->id, OrderStatus::Cancelled, 'oops'))->toThrow(OrderException::class, 'cancel_after_shipping');

        $paid = ($this->place)();
        $t->payment($this->staff, $paid->id, PaymentStatus::Paid);
        expect(fn () => $t->status($this->staff, $paid->id, OrderStatus::Cancelled, 'oops'))->toThrow(OrderException::class, 'cancel_needs_refund');
    });

    it('completes only when delivered and paid, and ships only once confirmed', function () {
        $t = app(TransitionOrder::class);
        $o = ($this->place)();

        expect(fn () => $t->fulfillment($this->staff, $o->id, FulfillmentStatus::Fulfilled))->toThrow(OrderException::class, 'confirm_before_shipping');
        $t->status($this->staff, $o->id, OrderStatus::Confirmed);
        $t->status($this->staff, $o->id, OrderStatus::Processing);
        expect(fn () => $t->status($this->staff, $o->id, OrderStatus::Completed))->toThrow(OrderException::class, 'complete_needs_delivery_and_payment');
        $t->fulfillment($this->staff, $o->id, FulfillmentStatus::Fulfilled);
        expect(fn () => $t->status($this->staff, $o->id, OrderStatus::Completed))->toThrow(OrderException::class, 'complete_needs_delivery_and_payment');
        $t->payment($this->staff, $o->id, PaymentStatus::Paid);
        expect($t->status($this->staff, $o->id, OrderStatus::Completed)->status)->toBe(OrderStatus::Completed);
    });

    it('has a state machine whose every listed move is reachable and whose finals are final', function () {
        foreach ([OrderStatus::class, PaymentStatus::class, FulfillmentStatus::class] as $enum) {
            foreach ($enum::cases() as $from) {
                foreach ($enum::cases() as $to) {
                    expect($from->canMoveTo($to))->toBe(in_array($to, $from->next(), true));
                }
                expect($from->canMoveTo($from))->toBeFalse();
            }
        }
        expect(OrderStatus::Completed->isFinal())->toBeTrue()->and(OrderStatus::Cancelled->isFinal())->toBeTrue()->and(OrderStatus::Pending->isOpen())->toBeTrue();
    });
});

describe('admin', function () {
    beforeEach(function () {
        $this->admin = fn (array $perms) => catalogStaff($perms);
    });

    it('lists orders with filters, a fixed number of queries, and masked phones without the PII permission', function () {
        foreach (range(1, 6) as $i) {
            ($this->place)();
        }
        $one = ($this->place)();
        app(TransitionOrder::class)->status($this->staff, $one->id, OrderStatus::Confirmed);
        $viewer = ($this->admin)(['orders.view']);

        $this->actingAs($viewer, 'admin')->get('/admin/orders')->assertOk()->assertSee($one->order_number)->assertSee('+88017', false)->assertDontSee('+8801712345678', false);
        $count = function () use ($viewer) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer, 'admin')->get('/admin/orders')->assertOk();

            return count(DB::getQueryLog());
        };
        $count();   // warm: permission cache, settings
        $queries = $count();
        foreach (range(1, 12) as $i) {
            ($this->place)();
        }
        expect($count())->toBe($queries);

        $this->actingAs($viewer, 'admin')->get('/admin/orders?status=confirmed')->assertOk()->assertSee($one->order_number);
        $this->actingAs($viewer, 'admin')->get('/admin/orders?status=cancelled')->assertOk()->assertDontSee($one->order_number);
        $this->actingAs($viewer, 'admin')->get('/admin/orders?q='.$one->order_number)->assertOk()->assertSee($one->order_number);
        $this->actingAs($viewer, 'admin')->get('/admin/orders?q=nobody-at-all')->assertOk()->assertDontSee($one->order_number);
        $this->actingAs($viewer, 'admin')->get('/admin/orders?status=bogus')->assertSessionHasErrors('status');

        $pii = ($this->admin)(['orders.view', 'customers.view_pii']);
        $this->actingAs($pii, 'admin')->get('/admin/orders')->assertSee('+8801712345678', false);
    });

    it('shows the order in both languages: lines, totals, address, history', function () {
        $o = ($this->place)(($this->breakdown)(orderDiscount: 500));
        $viewer = ($this->admin)(['orders.view']);

        $this->actingAs($viewer, 'admin')->get('/admin/orders/'.$o->public_id)->assertOk()->assertSee($o->order_number)->assertSee('Cotton Tee')->assertSee('TEE-1')->assertSee('House 5, Road 2')->assertSee('Dhanmondi')->assertSee('Order status')->assertSee('Payment');
        $this->actingAs($viewer, 'admin')->withSession(['locale' => 'bn'])->withHeader('Accept-Language', 'bn')->get('/admin/orders/'.$o->public_id)->assertOk()->assertSee($o->order_number);
        $this->actingAs($viewer, 'admin')->get('/admin/orders/'.($o->id + 999))->assertNotFound();
        $this->actingAs($viewer, 'admin')->get('/admin/orders/'.$o->id)->assertNotFound();   // internal ids never work
    });

    it('lets staff with orders.update move the order and add notes, with plain errors for illegal moves', function () {
        $o = ($this->place)();
        $staff = ($this->admin)(['orders.view', 'orders.update']);

        $this->actingAs($staff, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'confirmed'])->assertRedirect()->assertSessionHas('status');
        expect($o->fresh()->status)->toBe(OrderStatus::Confirmed);
        expect(DB::table('order_status_history')->where('order_id', $o->id)->where('to_state', 'confirmed')->value('actor_id'))->toBe($staff->id);

        $this->actingAs($staff, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'completed'])->assertSessionHasErrors('transition');
        $this->actingAs($staff, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'nonsense'])->assertSessionHasErrors('to');
        $this->actingAs($staff, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'money', 'to' => 'paid'])->assertSessionHasErrors('axis');
        $this->actingAs($staff, 'admin')->post("/admin/orders/{$o->public_id}/notes", ['body' => "  Call before delivery \n", 'visibility' => 'internal'])->assertRedirect();
        $this->actingAs($staff, 'admin')->post("/admin/orders/{$o->public_id}/notes", ['body' => '', 'visibility' => 'internal'])->assertSessionHasErrors('body');

        $this->actingAs($staff, 'admin')->get('/admin/orders/'.$o->public_id)->assertSee('Call before delivery');
        expect(DB::table('order_notes')->where('order_id', $o->id)->value('author_id'))->toBe($staff->id);
    });

    it('needs the cancel permission to cancel, and the update permission for anything', function () {
        $o = ($this->place)();
        $viewer = ($this->admin)(['orders.view']);
        $updater = ($this->admin)(['orders.view', 'orders.update']);
        $canceller = ($this->admin)(['orders.view', 'orders.update', 'orders.cancel']);

        $this->actingAs($viewer, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'confirmed'])->assertForbidden();
        $this->actingAs($viewer, 'admin')->post("/admin/orders/{$o->public_id}/notes", ['body' => 'x', 'visibility' => 'internal'])->assertForbidden();
        $this->actingAs($updater, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'cancelled', 'reason' => 'x'])->assertForbidden();
        expect($o->fresh()->status)->toBe(OrderStatus::Pending);

        $this->actingAs($canceller, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'cancelled', 'reason' => 'Duplicate order'])->assertRedirect()->assertSessionHas('status');
        expect($o->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('keeps orders away from anyone who is not signed in as staff, or has no orders permission', function () {
        $o = ($this->place)();
        $nobody = ($this->admin)(['catalog.view']);

        $this->get('/admin/orders')->assertRedirect();
        $this->get('/admin/orders/'.$o->public_id)->assertRedirect();
        $this->actingAs($nobody, 'admin')->get('/admin/orders')->assertForbidden();
        $this->actingAs($nobody, 'admin')->get('/admin/orders/'.$o->public_id)->assertForbidden();
        $this->actingAs($nobody, 'admin')->post("/admin/orders/{$o->public_id}/transition", ['axis' => 'status', 'to' => 'confirmed'])->assertForbidden();
    });
});

it('has Bangla wording for everything the order screens say', function () {
    $keys = function (array $a, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
        }

        return $out;
    };

    $en = $keys(require app_path('Modules/Orders/resources/lang/en/orders.php'));
    $bn = $keys(require app_path('Modules/Orders/resources/lang/bn/orders.php'));
    expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
});
