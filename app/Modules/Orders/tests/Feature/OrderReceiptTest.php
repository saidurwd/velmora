<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\CancelReason;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderReceipt;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    shippingWorld($this);
    $this->seed([PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->link = fn (string $publicId, string $locale = 'en', $expires = null) => URL::temporarySignedRoute('orders.receipt', $expires ?? now()->addDay(), ['locale' => $locale, 'order' => $publicId]);
    $this->receipt = fn (object $order, string $locale = 'en') => app(OrderReceipt::class)->for(Order::query()->findOrFail($order->id), $locale);
    $this->staff = catalogStaff(['orders.view']);
    $this->nobody = catalogStaff(['catalog.view']);
});

describe('the figures', function () {
    it('are the stored figures and add up: lines, delivery and cash fee make the total', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);

        $r = ($this->receipt)($order);

        expect($r['reconciles'])->toBeTrue()->and($r['totals'])->toMatchArray(['subtotal_minor' => 165000, 'shipping_minor' => 6000, 'cod_fee_minor' => 2000, 'discount_minor' => 0, 'tax_minor' => 0, 'grand_minor' => 173000])
            ->and(array_sum(array_map(fn ($l) => $l['unit_minor'] * $l['quantity'], $r['lines'])))->toBe(165000)->and($r['lines'])->toHaveCount(2);
        $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Order receipt')->assertSee($order->order_number)->assertSee('1,300.00')->assertSee('350.00')->assertSee('60.00')->assertSee('20.00')->assertSee('1,730.00')->assertSee('not a VAT invoice');
    });

    it('add up with discounts and with tax added on top, and with tax already inside the prices', function () {
        $added = ($this->order)([$this->tee => 2, $this->mug => 1], 'cod', ['lineDiscount' => 5000, 'orderDiscount' => 3000, 'taxBp' => 500]);
        $inside = ($this->order)([$this->tee => 1], 'cod', ['taxBp' => 500, 'inclusive' => true]);

        foreach ([$added, $inside] as $order) {
            $r = ($this->receipt)($order);
            expect($r['reconciles'])->toBeTrue()->and($r['totals']['grand_minor'])->toBe((int) $order->grand_total_minor);
        }
        expect(($this->receipt)($added)['totals']['tax_minor'])->toBeGreaterThan(0)->and(($this->receipt)($added)['totals']['discount_minor'])->toBe(13000)   // 5,000 off each of two lines, and 3,000 off the order
            ->and(($this->receipt)($inside)['totals']['tax_inclusive'])->toBeTrue();
        $this->get(($this->link)($added->public_id))->assertOk()->assertSee('Discount')->assertSee('Tax')->assertSee(number_format($added->grand_total_minor / 100, 2));
    });

    it('are never handed to a customer when they do not add up, and the shop is told', function () {
        $order = ($this->order)([$this->tee => 1]);
        $real = ($this->receipt)($order);   // the stored figures cannot be edited (orders are append-only), so the receipt builder is made to report a difference
        $this->mock(OrderReceipt::class, fn ($mock) => $mock->shouldReceive('for')->andReturn(array_replace($real, ['reconciles' => false])));
        Log::spy();

        $this->get(($this->link)($order->public_id))->assertNotFound();
        Log::shouldHaveReceived('error')->withArgs(fn (string $m) => $m === 'orders.receipt_does_not_reconcile')->once();
        $this->actingAs($this->staff, 'admin')->get("/admin/orders/{$order->public_id}/receipt")->assertOk()->assertSee('do not add up');   // staff see it, with a warning
    });

    it('notice a difference in any part: the total, the delivery, the cash fee, a discount or a line', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        $changed = function (callable $change) use ($order): bool {
            $model = Order::query()->with(['items', 'addresses', 'taxLines'])->findOrFail($order->id);   // changed in memory only: the stored order is append-only
            $change($model);

            return app(OrderReceipt::class)->for($model, 'en')['reconciles'];
        };

        expect($changed(fn () => null))->toBeTrue()
            ->and($changed(fn ($m) => $m->grand_total_minor += 1))->toBeFalse()
            ->and($changed(fn ($m) => $m->shipping_minor += 1))->toBeFalse()
            ->and($changed(fn ($m) => $m->cod_fee_minor -= 1))->toBeFalse()
            ->and($changed(fn ($m) => $m->discount_minor += 1))->toBeFalse()
            ->and($changed(fn ($m) => $m->items->first()->unit_price_minor += 1))->toBeFalse()
            ->and($changed(fn ($m) => $m->subtotal_minor += 1))->toBeFalse();
    });

    it('say cancelled on a cancelled order', function () {
        $order = ($this->order)([$this->tee => 1]);
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Cancelled, 'Changed my mind', CancelReason::Other);

        $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Cancelled');
    });
});

describe('who can open it', function () {
    it('needs a link we signed, that has not expired or been changed, for a customer', function () {
        $order = ($this->order)([$this->tee => 1]);
        $other = ($this->order)([$this->mug => 1]);
        $url = ($this->link)($order->public_id);

        $this->get($url)->assertOk();
        $this->get("/en/orders/{$order->public_id}/receipt")->assertForbidden();
        $this->get(($this->link)($order->public_id, 'en', now()->subMinute()))->assertForbidden();
        $this->get(str_replace($order->public_id, $other->public_id, $url))->assertForbidden();   // a link for one order does not open another
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->get(($this->link)('01zzzzzzzzzzzzzzzzzzzzzzzz'))->assertNotFound();
    });

    it('shows the name and address but never the phone number or any internal id', function () {
        $order = ($this->order)([$this->tee => 1]);

        $html = $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Rahim Uddin')->assertSee('House 5, Road 2')->getContent();

        expect($html)->not->toContain('01712345678')->not->toContain('8801712345678')->not->toContain('"id":'.$order->id);
    });

    it('is offered on the customer\'s own order page and the confirmation page, and on the staff order page only to staff who can see orders', function () {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $order = ($this->order)([$this->tee => 1]);
        DB::table('orders')->where('id', $order->id)->update(['user_id' => $owner->id]);

        $page = $this->actingAs($owner)->get("/en/account/orders/{$order->public_id}")->assertOk()->assertSee('Print your receipt')->getContent();
        preg_match('#href="([^"]*/receipt\?[^"]+)"#', $page, $m);
        $this->get(html_entity_decode($m[1] ?? ''))->assertOk()->assertSee('Order receipt');
        $this->actingAs($stranger)->get("/en/account/orders/{$order->public_id}")->assertNotFound();
        $this->get(URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'en', 'order' => $order->public_id]))->assertOk()->assertSee('Print your receipt');
        $this->actingAs($this->staff, 'admin')->get("/admin/orders/{$order->public_id}")->assertOk()->assertSee('Print order receipt');
    });

    it('needs the permission to see orders for staff, and is recorded', function () {
        $order = ($this->order)([$this->tee => 1]);
        $url = "/admin/orders/{$order->public_id}/receipt";

        $this->get($url)->assertRedirect();
        $this->actingAs($this->nobody, 'admin')->get($url)->assertForbidden();
        $this->actingAs($this->staff, 'admin')->get($url)->assertOk()->assertSee('Order receipt');
        expect(DB::table('audit_events')->where('action', 'order.receipt_printed')->where('subject_id', $order->public_id)->count())->toBe(1);
        $this->actingAs($this->staff, 'admin')->get('/admin/orders/01zzzzzzzzzzzzzzzzzzzzzzzz/receipt')->assertNotFound();
    });
});

describe('both languages', function () {
    it('prints in Bangla with the item names in Bangla and no missing text', function () {
        $order = ($this->order)([$this->tee => 1]);

        $html = $this->get(($this->link)($order->public_id, 'bn'))->assertOk()->assertSee('অর্ডারের রসিদ')->assertSee('পণ্য')->assertSee('এটি ভ্যাট চালান নয়')->getContent();

        expect($html)->not->toContain('orders::documents');
    });
});

describe('the business profile', function () {
    it('appears on the receipt, in the customer\'s language, only once staff have filled it in', function () {
        $order = ($this->order)([$this->tee => 1]);
        $this->get(($this->link)($order->public_id))->assertOk()->assertDontSee('BIN:');

        $settings = app(Settings::class);
        $settings->set('business.legal_name.en', 'Velmora Trading');
        $settings->set('business.legal_name.bn', 'ভেলমোরা ট্রেডিং');
        $settings->set('business.bin', '000123456-0101');
        $settings->set('business.invoice_footer.en', 'Thank you for shopping.');

        $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Velmora Trading')->assertSee('BIN: 000123456-0101')->assertSee('Thank you for shopping.');
        $this->get(($this->link)($order->public_id, 'bn'))->assertOk()->assertSee('ভেলমোরা ট্রেডিং')->assertSee('BIN: 000123456-0101')->assertSee('Thank you for shopping.');   // no Bangla footer: the English one
    });
});
