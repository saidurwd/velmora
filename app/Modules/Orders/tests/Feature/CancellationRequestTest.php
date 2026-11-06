<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\DecideCancellationRequest;
use App\Modules\Orders\Actions\RequestOrderCancellation;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Privacy\OrderPersonalData;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Mail::fake();
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->sms->fail = false;
    $this->amina = User::factory()->create(['email' => 'amina@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->manager = catalogStaff(['orders.view', 'orders.cancel']);
    $this->viewer = catalogStaff(['orders.view']);
    $this->staff = new Actor(ActorType::Staff, $this->manager->id, $this->manager->public_id, 'Manager');
    /** A paid, confirmed tee order that has not been sent: the case the feature is for. */
    $this->paid = function (?User $owner = null, ?array $lines = null) {
        $order = ($this->orderFor)(($owner ?? $this->amina)->id, $lines ?? [$this->tee['variantId'] => 1]);
        ($this->payOrder)($order->id);

        return $order;
    };
    $this->as = fn (?User $u = null) => $this->actingAs($u ?? $this->amina, 'web');
    $this->ask = fn ($order, array $over = [], ?User $as = null) => ($this->as)($as)->post("/en/account/orders/{$order->public_id}/cancellation-request", $over + ['note' => 'I ordered the wrong size']);
    $this->requests = fn () => DB::table('order_cancellation_requests')->orderBy('id')->get();
    $this->orderState = fn ($order) => DB::table('orders')->where('id', $order->id)->first(['status', 'payment_status', 'fulfillment_status', 'cancelled_at']);
    $this->decide = fn (string $action, string $id, array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->manager, 'admin')->post("/admin/orders/cancellation-requests/{$id}/{$action}", $data);
});

describe('who can ask', function () {
    it('is for signed-in customers', function () {
        $order = ($this->paid)();

        $this->post("/en/account/orders/{$order->public_id}/cancellation-request", ['note' => 'x'])->assertRedirect('/en/login');
        expect(($this->requests)())->toHaveCount(0);
    });

    it('is offered on the order page for a paid order that has not been sent', function () {
        $order = ($this->paid)();

        ($this->as)()->get("/en/account/orders/{$order->public_id}")->assertOk()->assertSee('Ask us to cancel')->assertSee('Ask us to cancel this order');
    });

    it('is not offered where the customer can cancel for themselves, or where it makes no sense', function () {
        $pending = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);          // not paid, not confirmed: they can just cancel
        $digital = ($this->paid)(null, [$this->ebook['variantId'] => 1]);                        // downloads have their own rules
        $sent = ($this->paid)();
        DB::table('orders')->where('id', $sent->id)->update(['fulfillment_status' => 'fulfilled']);
        $cancelled = ($this->paid)();
        DB::table('orders')->where('id', $cancelled->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);

        foreach ([$pending, $digital, $sent, $cancelled] as $order) {
            ($this->as)()->get("/en/account/orders/{$order->public_id}")->assertOk()->assertDontSee('Ask us to cancel this order');
            ($this->ask)($order)->assertSessionHasErrors('ask');
        }
        ($this->as)()->get("/en/account/orders/{$pending->public_id}")->assertSee('Cancel this order');
        expect(($this->requests)())->toHaveCount(0);
    });

    it('is "not found" for someone else\'s order', function () {
        $order = ($this->paid)($this->bob);

        ($this->ask)($order)->assertNotFound();
        expect(($this->requests)())->toHaveCount(0);
    });
});

describe('asking', function () {
    it('records the request and changes nothing about the order or its money', function () {
        $order = ($this->paid)();
        $before = ($this->orderState)($order);

        ($this->ask)($order, ['note' => '  I ordered the wrong size  '])->assertRedirect("/en/account/orders/{$order->public_id}")->assertSessionHas('status');

        $r = ($this->requests)()->first();
        expect($r->status)->toBe('requested')->and($r->note)->toBe('I ordered the wrong size')->and($r->user_id)->toBe($this->amina->id)->and($r->open_key)->toBe((string) $order->id)->and(($this->orderState)($order))->toEqual($before)
            ->and(DB::table('audit_events')->where('action', 'order.cancellation_requested')->count())->toBe(1);
        ($this->as)()->get("/en/account/orders/{$order->public_id}")->assertSee('Your request is with us');
    });

    it('allows one open request per order, and the database holds that line', function () {
        $order = ($this->paid)();

        ($this->ask)($order)->assertSessionHasNoErrors();
        ($this->ask)($order)->assertSessionHasErrors('ask');

        expect(($this->requests)())->toHaveCount(1);
        expect(fn () => DB::table('order_cancellation_requests')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => $order->id, 'user_id' => $this->amina->id, 'status' => 'requested', 'open_key' => (string) $order->id, 'created_at' => now(), 'updated_at' => now()]))
            ->toThrow(UniqueConstraintViolationException::class);
        expect(fn () => app(RequestOrderCancellation::class)->handle($this->amina->id, $order->public_id, null))->toThrow(OrderException::class);
    });

    it('keeps a note short and optional', function () {
        $order = ($this->paid)();
        ($this->ask)($order, ['note' => str_repeat('x', 256)])->assertSessionHasErrors('note');
        ($this->ask)($order, ['note' => ''])->assertSessionHasNoErrors();

        expect(($this->requests)()->first()->note)->toBeNull();
    });

    it('can be taken back while it waits, by the customer only, and then asked again', function () {
        $order = ($this->paid)();
        ($this->ask)($order);
        $r = ($this->requests)()->first();
        $url = "/en/account/orders/{$order->public_id}/cancellation-request/{$r->public_id}/withdraw";

        ($this->as)($this->bob)->post($url)->assertNotFound();
        expect(($this->requests)()->first()->status)->toBe('requested');
        ($this->as)()->post($url)->assertSessionHas('status');
        ($this->as)()->post($url)->assertSessionHasErrors('ask');

        $row = ($this->requests)()->first();
        expect($row->status)->toBe('withdrawn')->and($row->open_key)->toBeNull();
        ($this->ask)($order)->assertSessionHasNoErrors();
        expect(($this->requests)())->toHaveCount(2);
    });

    it('is throttled like other writes', function () {
        $order = ($this->paid)();
        foreach (range(1, 60) as $i) {
            ($this->ask)($order, ['note' => str_repeat('x', 256)]);
        }

        ($this->ask)($order)->assertStatus(429);
    });
});

describe('the shop\'s answer', function () {
    it('is only for staff who may cancel orders', function () {
        $order = ($this->paid)();
        ($this->ask)($order);
        $id = ($this->requests)()->first()->public_id;

        $this->get('/admin/orders/cancellation-requests')->assertRedirect();
        $this->actingAs($this->amina, 'web')->get('/admin/orders/cancellation-requests')->assertRedirect();
        $this->actingAs($this->viewer, 'admin')->get('/admin/orders/cancellation-requests')->assertForbidden();
        ($this->decide)('approve', $id, [], $this->viewer)->assertForbidden();
        ($this->decide)('reject', $id, ['note' => 'x'], $this->viewer)->assertForbidden();

        expect(($this->requests)()->first()->status)->toBe('requested');
    });

    it('lists the waiting requests with the order and the customer\'s words, oldest first', function () {
        $first = ($this->paid)();
        $second = ($this->paid)($this->bob);
        ($this->ask)($first, ['note' => 'Wrong size']);
        ($this->ask)($second, ['note' => 'Found it cheaper elsewhere'], $this->bob);

        $this->actingAs($this->manager, 'admin')->get('/admin/orders/cancellation-requests')->assertOk()->assertSee('Cancellation requests')->assertSeeInOrder([$first->order_number, 'Wrong size', $second->order_number, 'Found it cheaper elsewhere']);
    });

    it('lists in a fixed number of queries as it grows', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->manager, 'admin')->get('/admin/orders/cancellation-requests')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        ($this->ask)(($this->paid)());
        $queries();
        $few = $queries();
        foreach (range(1, 5) as $i) {
            ($this->ask)(($this->paid)());
        }
        $queries();

        expect($queries())->toBe($few);
    });

    it('agrees: tells the customer, and changes neither the order nor any money', function () {
        $order = ($this->paid)();
        ($this->ask)($order);
        $id = ($this->requests)()->first()->public_id;
        $before = ($this->orderState)($order);
        $payments = DB::table('payments')->count();

        ($this->decide)('approve', $id, ['note' => 'We will refund by bKash.'])->assertSessionHas('status');

        $r = ($this->requests)()->first();
        expect($r->status)->toBe('approved')->and($r->decided_by)->toBe($this->manager->id)->and($r->open_key)->toBe((string) $order->id)->and(($this->orderState)($order))->toEqual($before)->and(DB::table('payments')->count())->toBe($payments)
            ->and(DB::table('refunds')->count())->toBe(0)->and(DB::table('audit_events')->where('action', 'order.cancellation_approved')->count())->toBe(1);
        expect(collect($this->sms->sent())->pluck('message')->join(' '))->toContain('we agree to cancel order '.$order->order_number);
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('r@example.com') && str_contains($m->text, 'We will refund by bKash.') && str_contains($m->text, '/en/account/orders/'.$order->public_id));
        ($this->as)()->get("/en/account/orders/{$order->public_id}")->assertSee('We agree.')->assertSee('From us: We will refund by bKash.');
    });

    it('refuses with a reason the customer is told, frees them to ask again, and a refusal needs the reason', function () {
        $order = ($this->paid)();
        ($this->ask)($order);
        $id = ($this->requests)()->first()->public_id;

        expect(fn () => app(DecideCancellationRequest::class)->reject($this->staff, $id, '   '))->toThrow(OrderException::class);   // the action holds the line even when called directly
        ($this->decide)('reject', $id, ['note' => ''])->assertSessionHasErrors('note');
        expect(($this->requests)()->first()->status)->toBe('requested');
        ($this->decide)('reject', $id, ['note' => 'It has already been packed.'])->assertSessionHas('status');

        $r = ($this->requests)()->first();
        expect($r->status)->toBe('rejected')->and($r->open_key)->toBeNull()->and($r->decision_note)->toBe('It has already been packed.');
        expect(collect($this->sms->sent())->pluck('message')->join(' '))->toContain('could not cancel order');
        ($this->as)()->get("/en/account/orders/{$order->public_id}")->assertSee('We could not cancel this order. It has already been packed.');
        ($this->ask)($order)->assertSessionHasNoErrors();
        expect(($this->requests)())->toHaveCount(2);
    });

    it('answers only once, even if the page is submitted twice, and a withdrawn request cannot be answered', function () {
        $order = ($this->paid)();
        ($this->ask)($order);
        $id = ($this->requests)()->first()->public_id;

        ($this->decide)('approve', $id)->assertSessionHasNoErrors();
        ($this->decide)('approve', $id)->assertSessionHasErrors('request');
        ($this->decide)('reject', $id, ['note' => 'too late'])->assertSessionHasErrors('request');
        expect(DB::table('audit_events')->where('action', 'like', 'order.cancellation_%')->whereIn('action', ['order.cancellation_approved', 'order.cancellation_rejected'])->count())->toBe(1);

        $other = ($this->paid)($this->bob);
        ($this->ask)($other, [], $this->bob);
        $second = ($this->requests)()->last();
        ($this->as)($this->bob)->post("/en/account/orders/{$other->public_id}/cancellation-request/{$second->public_id}/withdraw");
        ($this->decide)('approve', $second->public_id)->assertSessionHasErrors('request');
        ($this->decide)('approve', str_repeat('0', 26))->assertSessionHasErrors('request');
    });

    it('is signalled to staff on the order itself while it is open', function () {
        $order = ($this->paid)();
        $this->actingAs($this->manager, 'admin')->get("/admin/orders/{$order->public_id}")->assertOk()->assertDontSee('asked to cancel');
        ($this->ask)($order);

        $this->actingAs($this->manager, 'admin')->get("/admin/orders/{$order->public_id}")->assertOk()->assertSee('Customer asked to cancel this order (Waiting).');
    });
});

describe('words and privacy', function () {
    it('is shown in English and in Bangla with no missing words', function () {
        $order = ($this->paid)();
        ($this->ask)($order);
        $this->manager->forceFill(['locale' => 'bn'])->save();

        foreach (['en', 'bn'] as $locale) {
            expect(($this->as)()->get("/{$locale}/account/orders/{$order->public_id}")->assertOk()->getContent())->not->toContain('orders::');
        }
        ($this->as)()->get("/bn/account/orders/{$order->public_id}")->assertSee('আপনার অনুরোধ আমাদের কাছে আছে');
        expect($this->actingAs($this->manager, 'admin')->get('/admin/orders/cancellation-requests')->assertOk()->assertSee('বাতিলের অনুরোধ')->getContent())->not->toContain('orders::');
    });

    it('has the same words in English and Bangla, and for the four messages', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Orders/resources/lang/en/cancel-requests.php'));
        $bn = $keys(require app_path('Modules/Orders/resources/lang/bn/cancel-requests.php'));
        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
        foreach (['order.cancel_request_approved.sms', 'order.cancel_request_approved.email', 'order.cancel_request_rejected.sms', 'order.cancel_request_rejected.email'] as $code) {
            foreach (['en', 'bn'] as $locale) {
                expect(implode(' ', array_filter(app(TemplateCatalog::class)->get($code)->defaults[$locale])))->toContain('{{ number }}');
            }
        }
    });

    it('puts the customer\'s requests in their data copy and clears their words on erasure, only theirs', function () {
        $mine = ($this->paid)();
        $theirs = ($this->paid)($this->bob);
        ($this->ask)($mine, ['note' => 'My own words']);
        ($this->ask)($theirs, ['note' => 'Their words'], $this->bob);
        $personal = app(OrderPersonalData::class);

        $copy = $personal->export($this->amina->id);
        expect(json_encode($copy))->toContain('My own words')->and(json_encode($copy))->not->toContain('Their words');

        $personal->erase($this->amina->id);
        $personal->erase($this->amina->id);

        expect(DB::table('order_cancellation_requests')->orderBy('id')->pluck('note')->all())->toBe([null, 'Their words'])->and(DB::table('order_cancellation_requests')->count())->toBe(2);
    });
});
