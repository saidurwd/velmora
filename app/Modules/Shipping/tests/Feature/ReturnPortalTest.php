<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\DecideReturn;
use App\Modules\Shipping\Actions\ReceiveReturn;
use App\Modules\Shipping\Actions\RequestReturn;
use App\Modules\Shipping\Privacy\ReturnPersonalData;
use App\Modules\Shipping\Services\ReturnPortal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    shippingWorld($this);
    $this->seed([PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Mail::fake();
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->sms->fail = false;
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->stranger = User::factory()->create(['email' => 'stranger@example.com']);
    /** A cash order of two tees (line 1) and a mug (line 2), owned by $owner, delivered in one parcel. */
    $this->delivered = function (?User $owner = null) {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1], 'cod');
        DB::table('orders')->where('id', $order->id)->update(['user_id' => ($owner ?? $this->owner)->id]);
        $parcel = ($this->parcel)($order, [$this->tee => 2, $this->mug => 1]);
        ($this->say)($parcel, 'DELIVERED', now()->addMinute()->format('Y-m-d H:i:s'));

        return DB::table('orders')->where('id', $order->id)->first();
    };
    $this->as = fn (?User $u = null) => $this->actingAs($u ?? $this->owner, 'web');
    $this->ask = fn (object $order, array $lines, array $over = [], ?User $as = null) => ($this->as)($as)->post("/en/account/orders/{$order->public_id}/return", $over + ['lines' => $lines, 'reason_code' => 'damaged', 'note' => 'Cracked handle']);
    $this->returns = fn () => DB::table('return_requests')->orderBy('id')->get();
    $this->customer = fn (?User $u = null) => new Actor(ActorType::Customer, ($u ?? $this->owner)->id, ($u ?? $this->owner)->public_id, 'Owner');
});

describe('asking from the order', function () {
    it('is for signed-in customers', function () {
        $order = ($this->delivered)();

        $this->get("/en/account/orders/{$order->public_id}/return")->assertRedirect('/en/login');
        $this->post("/en/account/orders/{$order->public_id}/return", ['lines' => [1 => 1], 'reason_code' => 'damaged'])->assertRedirect('/en/login');
        $this->get('/en/account/returns')->assertRedirect('/en/login');
        expect(($this->returns)())->toHaveCount(0);
    });

    it('shows what can go back and until when, on the form and as a button on the order page', function () {
        $order = ($this->delivered)();

        $html = ($this->as)()->get("/en/account/orders/{$order->public_id}/return")->assertOk()->assertSee('Send items back')->assertSee('Cotton Tee')->assertSee('Mug')->assertSee('2 can still go back')->getContent();
        expect($html)->toContain('name="lines[1]"')->and($html)->toContain('name="lines[2]"')->and($html)->toContain(now()->addDays(7)->format('Y'));
        ($this->as)()->get("/en/account/orders/{$order->public_id}")->assertOk()->assertSee('Send items back')->assertSee('/account/orders/'.$order->public_id.'/return');
    });

    it('is "not found" for someone else\'s order and an order that does not exist', function () {
        $order = ($this->delivered)();

        ($this->as)($this->stranger)->get("/en/account/orders/{$order->public_id}/return")->assertNotFound();
        ($this->ask)($order, [1 => 1], [], $this->stranger)->assertNotFound();
        ($this->as)()->get('/en/account/orders/'.str_repeat('0', 26).'/return')->assertNotFound();
        expect(($this->returns)())->toHaveCount(0);
    });

    it('looks up a line by its position only for the order\'s owner, even before the return action checks again', function () {
        $order = ($this->delivered)();
        $portal = app(ReturnPortal::class);

        expect($portal->itemAt($this->owner->id, $order->public_id, 1))->toBe(($this->itemOf)($order->id, $this->tee))->and($portal->itemAt($this->owner->id, $order->public_id, 2))->toBe(($this->itemOf)($order->id, $this->mug))
            ->and($portal->itemAt($this->stranger->id, $order->public_id, 1))->toBeNull()->and($portal->itemAt($this->owner->id, $order->public_id, 9))->toBeNull()->and($portal->lines($this->stranger->id, $order->public_id, 'en'))->toBeNull();
    });

    it('records a return for the lines and quantities chosen, saying who asked and why', function () {
        $order = ($this->delivered)();

        ($this->ask)($order, [1 => 1, 2 => 0], ['note' => '  Cracked handle  '])->assertRedirect('/en/account/returns')->assertSessionHas('status');

        $r = ($this->returns)()->first();
        expect($r->status)->toBe('requested')->and($r->kind)->toBe('customer')->and($r->requested_by)->toBe($this->owner->id)->and($r->reason_code)->toBe('damaged')->and($r->reason)->toBe('Cracked handle');
        $items = DB::table('return_items')->get();
        expect($items)->toHaveCount(1)->and((int) $items[0]->order_item_id)->toBe(($this->itemOf)($order->id, $this->tee))->and((int) $items[0]->quantity)->toBe(1);
    });

    it('takes a Bangla digit for a quantity', function () {
        $order = ($this->delivered)();

        ($this->ask)($order, [1 => '২'])->assertSessionHasNoErrors();

        expect((int) DB::table('return_items')->value('quantity'))->toBe(2);
    });

    it('refuses more than can go back, nothing chosen, a bad reason, and a line that is not there', function () {
        $order = ($this->delivered)();

        ($this->ask)($order, [1 => 3])->assertSessionHasErrors('return');
        ($this->ask)($order, [1 => 0, 2 => 0])->assertSessionHasErrors('lines');
        ($this->ask)($order, [1 => 1], ['reason_code' => 'because'])->assertSessionHasErrors('reason_code');
        ($this->ask)($order, [9 => 1])->assertNotFound();
        ($this->ask)($order, [1 => 'abc'])->assertSessionHasErrors();

        expect(($this->returns)())->toHaveCount(0);
    });

    it('cannot ask twice for the last unit', function () {
        $order = ($this->delivered)();

        ($this->ask)($order, [2 => 1])->assertSessionHasNoErrors();
        ($this->ask)($order, [2 => 1])->assertSessionHasErrors('return');

        expect(($this->returns)())->toHaveCount(1);
    });

    it('is refused for goods not delivered yet, and after the time to return has passed', function () {
        $order = ($this->order)([$this->tee => 1], 'cod');
        DB::table('orders')->where('id', $order->id)->update(['user_id' => $this->owner->id]);
        ($this->parcel)($order, [$this->tee => 1]);                                        // on its way, not delivered
        ($this->ask)($order, [1 => 1])->assertSessionHasErrors('return');
        ($this->as)()->get("/en/account/orders/{$order->public_id}/return")->assertOk()->assertSee('Not delivered yet');

        $done = ($this->delivered)();
        $this->travel(8)->days();
        ($this->ask)($done, [1 => 1])->assertSessionHasErrors('return');
        ($this->as)()->get("/en/account/orders/{$done->public_id}/return")->assertSee('The time to return this ended');
        ($this->as)()->get("/en/account/orders/{$done->public_id}")->assertDontSee("/account/orders/{$done->public_id}/return", false);
        expect(($this->returns)())->toHaveCount(0);
    });

    it('limits how often a customer can send requests, like other writes', function () {
        $order = ($this->delivered)();
        foreach (range(1, 60) as $i) {
            ($this->ask)($order, [1 => 0]);
        }

        ($this->ask)($order, [1 => 0])->assertStatus(429);
    });
});

describe('following a return', function () {
    it('lists only the customer\'s own returns, with what is coming back, the reason and what happens next', function () {
        $mine = ($this->delivered)();
        $theirs = ($this->delivered)($this->stranger);
        ($this->ask)($mine, [1 => 2]);
        ($this->ask)($theirs, [2 => 1], [], $this->stranger);

        $html = ($this->as)()->get('/en/account/returns')->assertOk()->assertSee('My returns')->assertSee('Cotton Tee')->assertSee('It arrived damaged')->assertSee('Waiting for us')->assertSee('We have not looked at this yet.')->getContent();
        expect($html)->not->toContain('Mug')->and(substr_count($html, 'Waiting for us'))->toBe(1);
        ($this->as)($this->stranger)->get('/en/account/returns')->assertSee('Mug')->assertDontSee('Cotton Tee');
    });

    it('shows the shop\'s answer: allowed, with who pays, or refused, with the reason', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1]);
        ($this->ask)($order, [2 => 1]);
        [$first, $second] = ($this->returns)()->all();
        app(DecideReturn::class)->authorize($this->staff, $first->public_id, 'shop');
        app(DecideReturn::class)->reject($this->staff, $second->public_id, 'It was used.');

        ($this->as)()->get('/en/account/returns')->assertSee('Allowed')->assertSee('We will pay for sending them back.')->assertSee('Not accepted')->assertSee('From us: It was used.')->assertSee('Please send the goods back');
    });

    it('reads a long list of returns in the same number of queries as a short one', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->as)()->get('/en/account/returns')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1]);
        $queries();
        $few = $queries();
        foreach (range(1, 4) as $i) {
            ($this->ask)(($this->delivered)(), [1 => 1]);
        }
        $queries();

        expect($queries())->toBe($few);
    });

    it('is shown in English and in Bangla with no missing words', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1]);

        foreach (['en', 'bn'] as $locale) {
            foreach (["/{$locale}/account/returns", "/{$locale}/account/orders/{$order->public_id}/return", "/{$locale}/account/orders/{$order->public_id}"] as $page) {
                expect(($this->as)()->get($page)->assertOk()->getContent())->not->toContain('shipping::');
            }
        }
        ($this->as)()->get('/bn/account/returns')->assertSee('আমার ফেরত');
    });

    it('has the same words in English and Bangla', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Shipping/resources/lang/en/returns.php'));
        $bn = $keys(require app_path('Modules/Shipping/resources/lang/bn/returns.php'));

        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    });
});

describe('withdrawing', function () {
    it('lets the customer take back a return that is waiting or allowed, and only their own', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1]);
        ($this->ask)($order, [2 => 1]);
        [$waiting, $allowed] = ($this->returns)()->all();
        app(DecideReturn::class)->authorize($this->staff, $allowed->public_id, 'none');

        ($this->as)($this->stranger)->post("/en/account/returns/{$waiting->public_id}/withdraw")->assertNotFound();
        expect(DB::table('return_requests')->where('id', $waiting->id)->value('status'))->toBe('requested');

        ($this->as)()->post("/en/account/returns/{$waiting->public_id}/withdraw")->assertSessionHas('status');
        ($this->as)()->post("/en/account/returns/{$allowed->public_id}/withdraw")->assertSessionHas('status');

        $rows = DB::table('return_requests')->orderBy('id')->get();
        expect($rows->pluck('status')->all())->toBe(['cancelled', 'cancelled'])->and($rows[0]->decision_note)->toBe('Withdrawn by the customer')->and($rows[0]->decided_by)->toBeNull();
        expect(DB::table('audit_events')->where('action', 'return.withdrawn')->count())->toBe(2);
    });

    it('is refused once the goods are received, or when already withdrawn, and the quantity is free to ask for again', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 2]);
        $return = ($this->returns)()->first();
        ($this->as)()->post("/en/account/returns/{$return->public_id}/withdraw")->assertSessionHasNoErrors();
        ($this->as)()->post("/en/account/returns/{$return->public_id}/withdraw")->assertSessionHasErrors('return');
        ($this->ask)($order, [1 => 2])->assertSessionHasNoErrors();   // withdrawn, so the two tees are free again

        $second = DB::table('return_requests')->orderByDesc('id')->first();
        app(DecideReturn::class)->authorize($this->staff, $second->public_id, 'none');
        app(ReceiveReturn::class)->handle($this->staff, $second->public_id, null);
        ($this->as)()->post("/en/account/returns/{$second->public_id}/withdraw")->assertSessionHasErrors('return');
        expect(DB::table('return_requests')->where('id', $second->id)->value('status'))->toBe('received');
    });

    it('changes no stock and no money', function () {
        $order = ($this->delivered)();
        $before = [DB::table('stock_levels')->sum('on_hand'), DB::table('orders')->where('id', $order->id)->value('grand_total_minor'), DB::table('payments')->count()];
        ($this->ask)($order, [1 => 1]);
        ($this->as)()->post('/en/account/returns/'.DB::table('return_requests')->value('public_id').'/withdraw');

        expect([DB::table('stock_levels')->sum('on_hand'), DB::table('orders')->where('id', $order->id)->value('grand_total_minor'), DB::table('payments')->count()])->toBe($before);
    });
});

describe('being told', function () {
    it('tells the customer by text when a return is allowed or refused, once, in the shop\'s words', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1]);
        ($this->ask)($order, [2 => 1]);
        [$first, $second] = ($this->returns)()->all();

        app(DecideReturn::class)->authorize($this->staff, $first->public_id, 'shop');
        app(DecideReturn::class)->reject($this->staff, $second->public_id, 'It was used.');

        $texts = collect($this->sms->sent())->where('to', '+8801712345678')->pluck('message')->all();
        expect(collect($texts)->filter(fn ($m) => str_contains($m, 'is allowed'))->count())->toBe(1)->and(collect($texts)->filter(fn ($m) => str_contains($m, 'could not accept'))->count())->toBe(1)
            ->and(collect($texts)->every(fn ($m) => str_contains($m, $order->order_number)))->toBeTrue()
            ->and(DB::table('notification_logs')->where('template_code', 'like', 'shipping.return_%')->count())->toBe(2);
    });

    it('does not undo a decision when the message cannot be recorded', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1]);
        $return = ($this->returns)()->first();
        $this->app->instance(NotificationSender::class, new class implements NotificationSender
        {
            public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string
            {
                throw new RuntimeException('could not record for +8801712345678');
            }
        });

        app(DecideReturn::class)->authorize($this->staff, $return->public_id, 'shop');

        expect(DB::table('return_requests')->where('id', $return->id)->value('status'))->toBe('authorized');
    });

    it('has words for the email and the text in both languages, with the order number and no loose braces', function () {
        $catalog = app(TemplateCatalog::class);
        foreach (['shipping.return_authorized.email', 'shipping.return_authorized.sms', 'shipping.return_rejected.email', 'shipping.return_rejected.sms'] as $code) {
            $definition = $catalog->get($code);
            foreach (['en', 'bn'] as $locale) {
                expect(implode(' ', array_filter($definition->defaults[$locale])))->toContain('{{ number }}');
            }
        }
        expect($catalog->get('shipping.return_authorized.email')->defaults['bn'][1])->toContain('ফেরত');
    });
});

describe('the words a customer typed', function () {
    it('can be cleared when they are erased, but never changed, and the return itself stays', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1], ['note' => 'My own words, with my details']);
        $id = DB::table('return_requests')->value('id');
        $change = fn (array $set) => fn () => DB::table('return_requests')->where('id', $id)->update($set);

        expect($change(['reason' => 'rewritten']))->toThrow(QueryException::class)->and($change(['reason_code' => 'other']))->toThrow(QueryException::class)->and($change(['reason' => null]))->not->toThrow(QueryException::class);
        expect(DB::table('return_requests')->where('id', $id)->first())->reason->toBeNull()->reason_code->toBe('damaged');
    });

    it('go into the customer\'s data copy, are cleared on erasure, and an open return blocks erasure', function () {
        $order = ($this->delivered)();
        ($this->ask)($order, [1 => 1], ['note' => 'My own words, with my details']);
        $personal = app(ReturnPersonalData::class);

        $copy = $personal->export($this->owner->id);
        expect($copy)->toHaveCount(1)->and($copy[0]['your_words'])->toBe('My own words, with my details')->and($copy[0]['items_asked'])->toBe(1)
            ->and($personal->export($this->stranger->id))->toBe([])->and($personal->blockers($this->owner->id))->toBe(['open']);

        $id = DB::table('return_requests')->value('id');
        foreach (['authorized', 'received'] as $status) {
            DB::table('return_requests')->where('id', $id)->update(['status' => $status]);
            expect($personal->blockers($this->owner->id))->toBe(['open']);   // goods allowed, or here and being looked at
        }
        $personal->erase($this->owner->id);
        $personal->erase($this->owner->id);

        expect(DB::table('return_requests')->count())->toBe(1)->and(DB::table('return_requests')->value('reason'))->toBeNull()->and(DB::table('return_requests')->value('reason_code'))->toBe('damaged');
    });

    it('are not touched for another customer', function () {
        $mine = ($this->delivered)();
        $theirs = ($this->delivered)($this->stranger);
        ($this->ask)($mine, [1 => 1], ['note' => 'mine']);
        ($this->ask)($theirs, [1 => 1], ['note' => 'theirs'], $this->stranger);

        app(ReturnPersonalData::class)->erase($this->owner->id);

        expect(DB::table('return_requests')->orderBy('id')->pluck('reason')->all())->toBe([null, 'theirs']);
    });
});

it('still lets staff start a return for the customer at any time, as before', function () {
    $order = ($this->delivered)();
    $this->travel(30)->days();

    $return = app(RequestReturn::class)->handle($this->staff, $order->public_id, [($this->itemOf)($order->id, $this->tee) => 1], 'other', 'Goodwill');

    expect($return->requested_by_type)->toBe('staff')->and(DB::table('return_requests')->count())->toBe(1);
});
