<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\CancelReason;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\RequestReturn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shippingWorld($this);
    $this->seed([PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->warehouse = catalogStaff(['shipping.view', 'shipping.create_shipment']);
    $this->manager = catalogStaff(['shipping.view', 'shipping.manage']);
    $this->finance = catalogStaff(['shipping.view', 'payments.reconcile']);
    $this->viewer = catalogStaff(['shipping.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn ($staff) => $this->actingAs($staff, 'admin');
    $this->at = fn (int $minutes) => now()->addMinutes($minutes)->format('Y-m-d H:i:s');
    $this->made = fn (array $byVariant, bool $handOver = false, string $method = 'cod') => ($this->parcel)(($this->order)($byVariant, $method), $byVariant, $handOver);
    $this->delivered = function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        DB::table('orders')->where('id', $order->id)->update(['user_id' => $this->owner->id]);
        $parcel = ($this->parcel)($order, [$this->tee => 2, $this->mug => 1]);
        ($this->say)($parcel, 'DELIVERED', ($this->at)(1));

        return DB::table('orders')->where('id', $order->id)->first();
    };
    $this->asked = fn (object $order, array $byVariant) => app(RequestReturn::class)->handle(new Actor(ActorType::Customer, $this->owner->id, $this->owner->public_id, 'Owner'), $order->public_id, collect($byVariant)->mapWithKeys(fn ($q, $v) => [($this->itemOf)($order->id, $v) => $q])->all(), 'damaged', 'Broken');
});

describe('who can do what', function () {
    it('keeps every screen away from guests and from staff without shipping permissions', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->made)([$this->mug => 1]);
        $urls = ['/admin/shipping/shipments', "/admin/shipping/shipments/{$parcel->public_id}", "/admin/shipping/orders/{$order->public_id}", '/admin/shipping/returns', '/admin/shipping/issues', '/admin/shipping/statements'];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect();
        }
        foreach ($urls as $url) {
            ($this->as)($this->nobody)->get($url)->assertForbidden();
            ($this->as)($this->viewer)->get($url)->assertOk();
        }
    });

    it('lets a viewer look but not act, and keeps each action to the permission it needs', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->made)([$this->mug => 1]);
        $delivered = ($this->delivered)();
        $return = ($this->asked)($delivered, [$this->tee => 1]);

        $acts = [
            ['post', "/admin/shipping/orders/{$order->public_id}/shipments", ['quantities' => [1 => 1]], 'shipping.create_shipment'],
            ['post', "/admin/shipping/shipments/{$parcel->public_id}/hand-over", [], 'shipping.create_shipment'],
            ['post', "/admin/shipping/shipments/{$parcel->public_id}/cancel", ['reason' => 'x'], 'shipping.create_shipment'],
            ['post', "/admin/shipping/shipments/{$parcel->public_id}/status", ['status' => 'delivered'], 'shipping.create_shipment'],
            ['post', "/admin/shipping/returns/{$return->public_id}/authorize", ['payer' => 'shop'], 'shipping.manage'],
            ['post', "/admin/shipping/returns/{$return->public_id}/reject", ['reason' => 'x'], 'shipping.manage'],
            ['post', "/admin/shipping/returns/{$return->public_id}/receive", [], 'shipping.create_shipment'],
            ['post', "/admin/shipping/returns/{$return->public_id}/inspect", ['line' => 1, 'outcome' => 'restock'], 'shipping.create_shipment'],
            ['post', '/admin/shipping/issues/abc/resolve', ['outcome' => 'resolved', 'note' => 'x'], 'shipping.manage'],
            ['post', '/admin/shipping/statements/import', [], 'payments.reconcile'],
        ];
        $holders = ['shipping.create_shipment' => $this->warehouse, 'shipping.manage' => $this->manager, 'payments.reconcile' => $this->finance];
        foreach ($acts as [$verb, $url, $data, $needs]) {
            ($this->as)($this->viewer)->{$verb}($url, $data)->assertForbidden();
            foreach ($holders as $permission => $staff) {
                if ($permission !== $needs) {
                    ($this->as)($staff)->{$verb}($url, $data)->assertForbidden();   // a different permission is not enough
                }
            }
        }
        expect(DB::table('shipments')->count())->toBe(2);   // none of that made anything
    });

    it('shows the action buttons only to those who may use them', function () {
        $parcel = ($this->made)([$this->mug => 1]);

        ($this->as)($this->viewer)->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertDontSee('Mark as handed over');
        ($this->as)($this->warehouse)->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertSee('Mark as handed over');
        ($this->as)($this->viewer)->get('/admin/shipping/statements')->assertOk()->assertDontSee('Check statement', false)->assertSee('needs the permission to reconcile payments');
        ($this->as)($this->finance)->get('/admin/shipping/statements')->assertOk()->assertSee('Check statement');
    });
});

describe('making and moving a parcel', function () {
    it('makes a parcel from the order page, with the quantities typed, and goes to the parcel', function () {
        $order = ($this->order)([$this->tee => 3, $this->mug => 1]);
        $tee = ($this->itemOf)($order->id, $this->tee);

        ($this->as)($this->warehouse)->get("/admin/shipping/orders/{$order->public_id}")->assertOk()->assertSee('Cotton Tee')->assertSee('Still to send')->assertSee('Make parcel');
        $response = ($this->as)($this->warehouse)->post("/admin/shipping/orders/{$order->public_id}/shipments", ['quantities' => [$tee => 2, ($this->itemOf)($order->id, $this->mug) => 0], 'courier' => $this->account]);

        $parcel = DB::table('shipments')->first();
        $response->assertRedirect("/admin/shipping/shipments/{$parcel->public_id}")->assertSessionHas('status', 'The parcel was made.');
        expect(DB::table('shipment_items')->where('shipment_id', $parcel->id)->pluck('quantity', 'order_item_id')->all())->toBe([$tee => 2])   // the zero line is left out
            ->and(DB::table('order_items')->where('id', $tee)->value('fulfilled_qty'))->toBe(2);
        ($this->as)($this->warehouse)->get("/admin/shipping/orders/{$order->public_id}")->assertOk()->assertSee('Parcels')->assertSee(strtoupper(substr($parcel->public_id, -8)));
    });

    it('explains a refusal in words instead of failing: more than is left, nothing chosen, an order not ready, an unknown order', function () {
        $order = ($this->order)([$this->tee => 1]);
        $tee = ($this->itemOf)($order->id, $this->tee);
        $post = fn (string $id, array $data) => ($this->as)($this->warehouse)->post("/admin/shipping/orders/{$id}/shipments", $data);

        $post($order->public_id, ['quantities' => [$tee => 5]])->assertSessionHasErrors(['parcel' => 'That is more than is left to send.']);
        $post($order->public_id, ['quantities' => [$tee => 0]])->assertSessionHasErrors(['parcel' => 'Choose what goes in the parcel.']);
        $post('01zzzzzzzzzzzzzzzzzzzzzzzz', ['quantities' => [1 => 1]])->assertSessionHasErrors(['parcel' => 'That order was not found.']);
        $post($order->public_id, ['quantities' => [$tee => 'abc']])->assertSessionHasErrors('quantities.'.$tee);
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Cancelled, 'Changed my mind', CancelReason::Other);
        $post($order->public_id, ['quantities' => [$tee => 1]])->assertSessionHasErrors(['parcel' => 'This order is cancelled.']);
        ($this->as)($this->warehouse)->get("/admin/shipping/orders/{$order->public_id}")->assertOk()->assertSee('Parcels cannot be made yet')->assertSee('This order is cancelled.');
        expect(DB::table('shipments')->count())->toBe(0);
        ($this->as)($this->warehouse)->get('/admin/shipping/orders/01zzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    });

    it('hands over, then cancels only before the handover, with a reason', function () {
        $a = ($this->made)([$this->mug => 1]);
        $b = ($this->made)([$this->tee => 1]);

        ($this->as)($this->warehouse)->post("/admin/shipping/shipments/{$a->public_id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->warehouse)->post("/admin/shipping/shipments/{$a->public_id}/cancel", ['reason' => 'Customer called'])->assertSessionHas('status', 'The parcel was cancelled.');
        ($this->as)($this->warehouse)->post("/admin/shipping/shipments/{$b->public_id}/hand-over")->assertSessionHas('status', 'The parcel is marked as handed over.');
        ($this->as)($this->warehouse)->post("/admin/shipping/shipments/{$b->public_id}/cancel", ['reason' => 'Too late'])->assertSessionHasErrors(['parcel' => 'This parcel has left the shop and cannot be cancelled. Follow it to delivery or return.']);

        expect(($this->shipmentRow)($a->public_id)->status)->toBe('cancelled')->and(($this->shipmentRow)($b->public_id)->status)->toBe('handed_over')
            ->and(DB::table('audit_events')->where('action', 'like', 'shipment.%')->count())->toBeGreaterThan(0);
        ($this->as)($this->warehouse)->post('/admin/shipping/shipments/nope/hand-over')->assertSessionHasErrors(['parcel' => 'That parcel was not found.']);
    });

    it('books again on request, and records where a manual parcel is', function () {
        $manual = (string) DB::table('courier_accounts')->where('courier', 'manual')->value('public_id');
        $order = ($this->order)([$this->mug => 1]);
        $this->actingAs($this->warehouse, 'admin');
        $this->post("/admin/shipping/orders/{$order->public_id}/shipments", ['quantities' => [($this->itemOf)($order->id, $this->mug) => 1], 'courier' => $manual]);
        $parcel = DB::table('shipments')->first();

        $this->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertDontSee('Book with the courier')->assertSee('Mark as handed over');   // nothing to book for the manual courier
        $this->post("/admin/shipping/shipments/{$parcel->public_id}/hand-over")->assertSessionHasNoErrors();
        $this->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertSee('Record where the parcel is');
        $this->post("/admin/shipping/shipments/{$parcel->public_id}/status", ['status' => 'bogus'])->assertSessionHasErrors(['parcel' => 'Choose where the parcel is.']);
        $this->post("/admin/shipping/shipments/{$parcel->public_id}/status", ['status' => 'delivered', 'note' => 'Signed by Rahim'])->assertSessionHas('status', 'The parcel’s status was recorded.');

        expect(($this->shipmentRow)($parcel->public_id)->status)->toBe('delivered');
        $this->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertSee('Signed by Rahim')->assertSee('Delivered')->assertDontSee('Record where the parcel is');

        $other = ($this->made)([$this->tee => 1]);
        $this->post("/admin/shipping/shipments/{$other->public_id}/book")->assertSessionHasNoErrors();   // the fake courier was already booked at creation: nothing more to do, and no error
    });
});

describe('the lists and the parcel page', function () {
    it('filters parcels by status, courier and tracking code, and shows each order', function () {
        $a = ($this->made)([$this->mug => 1], true);
        $b = ($this->made)([$this->tee => 1], false);
        ($this->say)($a, 'IN_TRANSIT', ($this->at)(1));
        $this->actingAs($this->viewer, 'admin');

        $this->get('/admin/shipping/shipments')->assertOk()->assertSee(strtoupper(substr($a->public_id, -8)))->assertSee(strtoupper(substr($b->public_id, -8)));
        $this->get('/admin/shipping/shipments?status=in_transit')->assertOk()->assertSee(strtoupper(substr($a->public_id, -8)))->assertDontSee(strtoupper(substr($b->public_id, -8)));
        $this->get('/admin/shipping/shipments?status=created')->assertOk()->assertSee(strtoupper(substr($b->public_id, -8)))->assertDontSee(strtoupper(substr($a->public_id, -8)));
        $this->get('/admin/shipping/shipments?courier=fake')->assertOk()->assertSee(strtoupper(substr($a->public_id, -8)));
        $this->get('/admin/shipping/shipments?courier=nobody')->assertOk()->assertSee('No parcels match.');
        $this->get('/admin/shipping/shipments?status=garbage&courier=%27%3B--&q='.str_repeat('x', 500))->assertOk();   // junk is ignored, never an error
        $code = (string) DB::table('shipments')->where('id', $a->id)->value('courier_consignment_id');
        $this->get('/admin/shipping/shipments?q='.$code)->assertOk()->assertSee(strtoupper(substr($a->public_id, -8)))->assertDontSee(strtoupper(substr($b->public_id, -8)));
    });

    it('lists parcels in a fixed number of queries, however many there are', function () {
        $this->actingAs($this->viewer, 'admin');
        ($this->made)([$this->mug => 1]);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/shipping/shipments')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->get('/admin/shipping/shipments');   // warm up caches
        $few = $count();
        foreach ([$this->tee, $this->mug, $this->tee, $this->mug] as $v) {
            ($this->made)([$v => 1]);
        }
        expect($count())->toBe($few);
    });

    it('shows a parcel with its items, its cash and its whole history including the courier\'s own words', function () {
        $order = ($this->order)([$this->tee => 2]);
        $parcel = ($this->parcel)($order, [$this->tee => 2]);
        ($this->say)($parcel, 'IN_TRANSIT', ($this->at)(1), 'At the sorting centre');

        ($this->as)($this->viewer)->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertSee('2 × Cotton Tee', false)->assertSee('1,380.00')->assertSee($order->order_number)
            ->assertSee('IN_TRANSIT')->assertSee('On the way')->assertSee('At the sorting centre')->assertSee('Courier message')->assertSee('Handed to the courier');
    });

    it('shows the order page link on the order, only to those who can see parcels', function () {
        $order = ($this->order)([$this->tee => 1]);
        $viewer = catalogStaff(['orders.view', 'shipping.view']);
        $plain = catalogStaff(['orders.view']);

        ($this->as)($viewer)->get("/admin/orders/{$order->public_id}")->assertOk()->assertSee('Parcels for this order')->assertSee("/admin/shipping/orders/{$order->public_id}", false);
        ($this->as)($plain)->get("/admin/orders/{$order->public_id}")->assertOk()->assertDontSee('Parcels for this order');
    });
});

describe('returns', function () {
    it('goes from asked, to allowed, to counted, to looked at, and only suggests a refund', function () {
        $order = ($this->delivered)();
        $return = ($this->asked)($order, [$this->tee => 1]);
        $page = "/admin/shipping/returns/{$return->public_id}";

        ($this->as)($this->manager)->get('/admin/shipping/returns')->assertOk()->assertSee('Waiting for a decision')->assertSee($order->order_number);
        ($this->as)($this->manager)->get($page)->assertOk()->assertSee('Allow this return')->assertSee('Cotton Tee');
        ($this->as)($this->manager)->post("{$page}/authorize", ['payer' => 'nobody'])->assertSessionHasErrors('payer');
        ($this->as)($this->manager)->post("{$page}/authorize", ['payer' => 'shop'])->assertSessionHas('status', 'The return was allowed.');
        ($this->as)($this->manager)->post("{$page}/authorize", ['payer' => 'shop'])->assertSessionHasErrors('return');   // the first decision wins

        ($this->as)($this->warehouse)->get($page)->assertOk()->assertSee('Goods are back');
        ($this->as)($this->warehouse)->post("{$page}/receive", ['received' => [($this->itemOf)($order->id, $this->tee) => 9]])->assertSessionHasErrors('return');   // more than asked
        ($this->as)($this->warehouse)->post("{$page}/receive")->assertSessionHas('status', 'The goods were counted as back.');
        ($this->as)($this->warehouse)->get($page)->assertOk()->assertSee('Save result');

        $line = ($this->itemOf)($order->id, $this->tee);
        ($this->as)($this->warehouse)->post("{$page}/inspect", ['line' => $line, 'outcome' => 'not_received'])->assertSessionHasErrors('return');
        ($this->as)($this->warehouse)->post("{$page}/inspect", ['line' => $line, 'outcome' => 'restock', 'note' => 'Like new'])->assertSessionHas('status', 'The result was saved.');

        expect(DB::table('return_requests')->where('public_id', $return->public_id)->value('status'))->toBe('closed')->and(DB::table('refunds')->count())->toBe(0);
        ($this->as)($this->viewer)->get($page)->assertOk()->assertSee('Back on the shelf')->assertSee('Like new')->assertSee('Suggested refund')->assertSee('Nothing has been refunded')->assertDontSee('Allow this return');
    });

    it('lets a manager refuse or cancel with a reason, and filters the list', function () {
        $order = ($this->delivered)();
        $one = ($this->asked)($order, [$this->tee => 1]);
        $two = ($this->asked)($order, [$this->mug => 1]);

        ($this->as)($this->manager)->post("/admin/shipping/returns/{$one->public_id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->manager)->post("/admin/shipping/returns/{$one->public_id}/reject", ['reason' => 'Used'])->assertSessionHas('status', 'The return was refused.');
        ($this->as)($this->manager)->post("/admin/shipping/returns/{$two->public_id}/cancel", ['reason' => 'Asked by mistake'])->assertSessionHas('status', 'The return was cancelled.');

        ($this->as)($this->viewer)->get('/admin/shipping/returns?status=rejected')->assertOk()->assertSee(strtoupper(substr($one->public_id, -8)))->assertDontSee(strtoupper(substr($two->public_id, -8)));
        ($this->as)($this->viewer)->get('/admin/shipping/returns?status=whatever')->assertOk()->assertSee(strtoupper(substr($two->public_id, -8)));
        ($this->as)($this->viewer)->get('/admin/shipping/returns/nope')->assertNotFound();
    });
});

describe('delivery problems', function () {
    it('lists what the system could not decide, and closes each with who and why, once', function () {
        $parcel = ($this->made)([$this->mug => 1], true);
        ($this->say)($parcel, 'WEIRD_WORD', ($this->at)(1));
        $issue = DB::table('shipment_exceptions')->first();

        ($this->as)($this->viewer)->get('/admin/shipping/issues')->assertOk()->assertSee('The courier used a status we do not know')->assertSee(strtoupper(substr($parcel->public_id, -8)))->assertDontSee('What was done', false);
        ($this->as)($this->manager)->get('/admin/shipping/issues')->assertOk()->assertSee('What was done');
        ($this->as)($this->manager)->post("/admin/shipping/issues/{$issue->public_id}/resolve", ['outcome' => 'resolved', 'note' => ''])->assertSessionHasErrors('note');
        ($this->as)($this->manager)->post("/admin/shipping/issues/{$issue->public_id}/resolve", ['outcome' => 'deleted', 'note' => 'x'])->assertSessionHasErrors('outcome');
        ($this->as)($this->manager)->post("/admin/shipping/issues/{$issue->public_id}/resolve", ['outcome' => 'resolved', 'note' => 'Asked the courier'])->assertSessionHas('status', 'Closed.');
        ($this->as)($this->manager)->post("/admin/shipping/issues/{$issue->public_id}/resolve", ['outcome' => 'ignored', 'note' => 'again'])->assertSessionHasErrors(['issue' => 'This item was already closed.']);

        ($this->as)($this->viewer)->get('/admin/shipping/issues')->assertOk()->assertSee('Nothing here.');
        ($this->as)($this->viewer)->get('/admin/shipping/issues?status=resolved')->assertOk()->assertSee('Asked the courier');
        expect(DB::table('shipment_exceptions')->where('id', $issue->id)->first())->toMatchObject(['status' => 'resolved', 'resolution' => 'Asked the courier']);
    });
});

describe('courier statements', function () {
    it('checks an uploaded statement, shows what matched, and refuses what is not usable', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($parcel, 'DELIVERED', ($this->at)(1));
        $row = DB::table('shipments')->where('id', $parcel->id)->first();
        $csv = "consignment_id,status,cod_amount,courier_fee,delivered_at\n{$row->courier_consignment_id},DELIVERED,730.00,60.00,2026-11-02T10:00:00Z\nNOPE-1,DELIVERED,10.00,1.00,2026-11-02T10:00:00Z";
        $send = fn (string $content, array $over = []) => ($this->as)($this->finance)->post('/admin/shipping/statements/import', array_replace([
            'courier' => 'fake', 'statement' => 'ST-9', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'received' => '670', 'file' => UploadedFile::fake()->createWithContent('st.csv', $content),
        ], $over));

        $send($csv, ['received' => 'abc'])->assertSessionHasErrors('statement');
        $send($csv, ['from' => now()->addDay()->format('Y-m-d'), 'to' => now()->subDay()->format('Y-m-d')])->assertSessionHasErrors('statement');
        $send("\xff\xfe\x00bad")->assertSessionHasErrors(['statement' => 'The file must be saved as UTF-8 text.']);
        $send($csv, ['courier' => 'nobody'])->assertSessionHasErrors(['statement' => 'That courier is not set up.']);
        expect(DB::table('courier_statements')->count())->toBe(0);

        $statement = null;
        $send($csv)->assertSessionHas('status', 'The statement was checked.')->assertRedirect();
        $statement = DB::table('courier_statements')->first();
        expect($statement->received_minor)->toBe(67000)->and($statement->fee_minor)->toBe(6100);   // the courier's charge is kept for the parcel we do not know too

        ($this->as)($this->viewer)->get("/admin/shipping/statements/{$statement->public_id}")->assertOk()->assertSee('Matched')->assertSee('Not our parcel')->assertSee('NOPE-1');
        ($this->as)($this->viewer)->get('/admin/shipping/statements')->assertOk()->assertSee('ST-9');
        $send($csv)->assertSessionHasErrors(['statement' => 'This statement was already checked.']);   // the same file twice is refused
    });
});

describe('both languages', function () {
    it('shows every new screen in Bangla with no missing text', function () {
        $this->warehouse->forceFill(['locale' => 'bn'])->save();
        $order = ($this->delivered)();
        $return = ($this->asked)($order, [$this->tee => 1]);
        $parcel = DB::table('shipments')->first();
        ($this->say)($parcel, 'WEIRD_WORD', ($this->at)(2));
        $pages = ['/admin/shipping/shipments', "/admin/shipping/shipments/{$parcel->public_id}", "/admin/shipping/orders/{$order->public_id}", '/admin/shipping/returns', "/admin/shipping/returns/{$return->public_id}", '/admin/shipping/issues', '/admin/shipping/statements'];

        foreach ($pages as $page) {
            $html = ($this->as)($this->warehouse)->get($page)->assertOk()->getContent();
            expect($html)->not->toContain('shipping::')->not->toContain('fulfilment.');
        }
        ($this->as)($this->warehouse)->get('/admin/shipping/shipments')->assertSee('পার্সেল')->assertSee('পৌঁছে গেছে');
        ($this->as)($this->warehouse)->get('/admin/shipping/issues')->assertSee('কুরিয়ার এমন একটি অবস্থা লিখেছে');
    });
});

describe('delivery rules', function () {
    it('shows the rules to anyone who can see parcels, and lets only those who manage settings change them, with a record', function () {
        $owner = catalogStaff(['shipping.view', 'settings.manage']);

        ($this->as)($this->viewer)->get('/admin/shipping/settings')->assertOk()->assertSee('Delivery rules')->assertSee('value="7"', false)->assertSee('changing them needs the permission to manage settings');
        ($this->as)($this->viewer)->put('/admin/shipping/settings', ['window' => 14, 'attempts' => 2])->assertForbidden();
        ($this->as)($this->manager)->put('/admin/shipping/settings', ['window' => 14, 'attempts' => 2])->assertForbidden();   // managing returns is not managing settings
        ($this->as)($owner)->put('/admin/shipping/settings', ['window' => 0, 'attempts' => 2])->assertSessionHasErrors('window');
        ($this->as)($owner)->put('/admin/shipping/settings', ['window' => 14, 'attempts' => 11])->assertSessionHasErrors('attempts');
        ($this->as)($owner)->put('/admin/shipping/settings', ['window' => 14, 'attempts' => 2])->assertSessionHas('status', 'Saved.');

        ($this->as)($owner)->get('/admin/shipping/settings')->assertOk()->assertSee('value="14"', false)->assertSee('value="2"', false);
        expect(DB::table('audit_events')->where('action', 'shipping.settings_changed')->count())->toBe(1);
    });

    it('gives a parcel as many delivery attempts as the setting says', function () {
        $owner = catalogStaff(['shipping.view', 'settings.manage']);
        ($this->as)($owner)->put('/admin/shipping/settings', ['window' => 7, 'attempts' => 1]);
        $parcel = ($this->made)([$this->mug => 1], true);

        ($this->say)($parcel, 'DELIVERY_FAILED', ($this->at)(1));

        expect(DB::table('shipment_exceptions')->where('type', 'attempts_exhausted')->count())->toBe(1);   // the first failure was already the last
    });
});
