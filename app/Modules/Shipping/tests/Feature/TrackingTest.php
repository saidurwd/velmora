<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Shipping\Actions\CancelShipment;
use App\Modules\Shipping\Actions\CreateShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    shippingWorld($this);
    $this->seed(PaymentMethodSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->link = fn (string $publicId, string $locale = 'en', $expires = null) => URL::temporarySignedRoute('orders.track', $expires ?? now()->addDay(), ['locale' => $locale, 'order' => $publicId]);
    $this->at = fn (int $minutes) => now()->addMinutes($minutes)->format('Y-m-d H:i:s');
});

describe('what a customer sees', function () {
    it('shows each parcel\'s stage in plain words, its items, the cash to pay, and the timeline in our own statuses', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 2]);
        ($this->say)($parcel, 'IN_TRANSIT', ($this->at)(1));
        ($this->say)($parcel, 'OUT_FOR_DELIVERY', ($this->at)(2));

        $page = $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Track your order')->assertSee($order->order_number)->assertSee('Parcel 1')->assertSee('Out for delivery')
            ->assertSee('Cotton Tee × 2', false)->assertSee('To pay when it arrives')->assertSee('1,300.00')->assertSee('Not sent yet')->assertSee('Mug × 1', false)->assertSee('Being prepared')->assertSee('On the way')->assertSee('Handed to the courier');

        preg_match('#<ol[^>]*>(.*?)</ol>#s', $page->getContent(), $m);
        $timeline = trim(preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
        expect($timeline)->toStartWith('Being prepared')->and(strpos($timeline, 'Handed to the courier'))->toBeLessThan(strpos($timeline, 'On the way'))->and(strpos($timeline, 'On the way'))->toBeLessThan(strpos($timeline, 'Out for delivery'));   // oldest first
    });

    it('shows it all in Bangla too, with the item names in Bangla', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($parcel, 'DELIVERED', ($this->at)(1));

        $this->get(($this->link)($order->public_id, 'bn'))->assertOk()->assertSee('আপনার অর্ডার ট্র্যাক করুন')->assertSee('পার্সেল')->assertSee('পৌঁছে গেছে')->assertSee('পণ্য')->assertSee('এই অর্ডারের সবকিছু পৌঁছে গেছে');
    });

    it('says the order is being prepared when nothing has been sent, and that it was cancelled when it was', function () {
        $order = ($this->order)([$this->tee => 1]);
        $this->get(($this->link)($order->public_id))->assertOk()->assertSee('We are preparing your order');

        $cancelled = ($this->order)([$this->mug => 1]);
        app(TransitionOrder::class)->status(Actor::system('t'), $cancelled->id, OrderStatus::Cancelled, 'Changed my mind');
        $this->get(($this->link)($cancelled->public_id))->assertOk()->assertSee('This order was cancelled.')->assertDontSee('Not sent yet');
    });

    it('no longer asks for cash once a parcel is delivered, and shows a cancelled parcel as cancelled', function () {
        $order = ($this->order)([$this->tee => 1, $this->mug => 1]);
        $a = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($a, 'DELIVERED', ($this->at)(1));
        $b = app(CreateShipment::class)->handle($this->staff, $order->public_id, [($this->itemOf)($order->id, $this->mug) => 1]);
        app(CancelShipment::class)->handle($this->staff, $b->public_id, 'Wrong box');

        $html = $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Parcel 2')->assertSee('Cancelled')->getContent();

        expect(substr_count($html, 'To pay when it arrives'))->toBe(0);
    });

    it('shows a failed attempt and how many have not worked', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($parcel, 'OUT_FOR_DELIVERY', ($this->at)(1));
        ($this->say)($parcel, 'DELIVERY_FAILED', ($this->at)(2));

        $this->get(($this->link)($order->public_id))->assertOk()->assertSee('Delivery did not work, we will try again')->assertSee('Delivery attempts that did not work');
    });
});

describe('what a customer never sees', function () {
    it('never shows the address, the phone, the courier\'s own words or notes, an unknown status, or any id', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($parcel, 'OUT_FOR_DELIVERY', ($this->at)(1), 'Rider Karim 01711111111 at the gate');
        ($this->say)($parcel, 'SORTING_HUB_X', ($this->at)(2), 'secret hub note');

        $html = $this->get(($this->link)($order->public_id))->assertOk()->getContent();

        foreach (['House 5', 'Road 2', 'Dhanmondi', '01712345678', '8801712345678', 'Karim', '01711111111', 'secret hub note', 'SORTING_HUB_X', 'OUT_FOR_DELIVERY', $parcel->public_id, 'Rahim Uddin'] as $private) {
            expect($html)->not->toContain($private);
        }
        expect($html)->not->toContain('Updating');   // an event we do not understand is not part of the story
    });

    it('shows only the courier\'s plain https link, never a script or a data address', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);

        $this->get(($this->link)($order->public_id))->assertOk()->assertSee('https://courier.example/track/', false)->assertSee('See it on the courier');
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', 'http://insecure.example/x', 'https://x.example/"onmouseover="alert(1)'] as $bad) {
            DB::table('shipments')->where('id', $parcel->id)->update(['tracking_url' => $bad]);
            $this->get(($this->link)($order->public_id))->assertOk()->assertDontSee('See it on the courier')->assertDontSee('alert(1)', false);
        }
    });

    it('escapes what the shop wrote', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);
        DB::table('shipments')->where('id', $parcel->id)->update(['tracking_code' => '<script>alert(1)</script>']);

        $this->get(($this->link)($order->public_id))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    });

    it('shows nothing of any other order', function () {
        $mine = ($this->order)([$this->tee => 1]);
        $other = ($this->order)([$this->mug => 1]);
        ($this->parcel)($other, [$this->mug => 1]);

        $this->get(($this->link)($mine->public_id))->assertOk()->assertDontSee($other->order_number)->assertDontSee('Mug × 1', false);
    });
});

describe('the link', function () {
    it('needs our signature: no signature, a changed one, another order\'s, an expired one are all refused', function () {
        $a = ($this->order)([$this->tee => 1]);
        $b = ($this->order)([$this->mug => 1]);
        $link = ($this->link)($a->public_id);

        $this->get("/en/orders/{$a->public_id}/track")->assertForbidden();
        $this->get($link.'x')->assertForbidden();
        $this->get(str_replace($a->public_id, $b->public_id, $link))->assertForbidden();   // the signature covers the order
        $this->get(str_replace('/en/', '/bn/', $link))->assertForbidden();
        $this->get(($this->link)($a->public_id, 'en', now()->subMinute()))->assertForbidden();
        $this->get($link)->assertOk();
    });

    it('has no sequence number in it: a number is never an order', function () {
        $order = ($this->order)([$this->tee => 1]);

        expect(($this->link)($order->public_id))->not->toContain('/orders/'.$order->id.'/');
        $this->get('/en/orders/'.$order->id.'/track')->assertForbidden();
        $this->get(URL::temporarySignedRoute('orders.track', now()->addDay(), ['locale' => 'en', 'order' => (string) $order->id]))->assertNotFound();
    });

    it('says not found for a signed link to an order that does not exist', function () {
        $this->get(URL::temporarySignedRoute('orders.track', now()->addDay(), ['locale' => 'en', 'order' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
    });

    it('is rate limited', function () {
        $order = ($this->order)([$this->tee => 1]);
        $link = ($this->link)($order->public_id);

        foreach (range(1, 120) as $i) {
            $this->get($link)->assertOk();
        }

        $this->get($link)->assertStatus(429);
    });

    it('does not run more queries for more parcels and events', function () {
        $order = ($this->order)([$this->tee => 4]);
        $first = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($first, 'IN_TRANSIT', ($this->at)(1));
        $link = ($this->link)($order->public_id);
        $count = function () use ($link) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($link)->assertOk();

            return count(DB::getQueryLog());
        };
        $count();
        $few = $count();

        foreach (range(1, 3) as $i) {
            $p = ($this->parcel)($order, [$this->tee => 1]);
            ($this->say)($p, 'IN_TRANSIT', ($this->at)(1));
            ($this->say)($p, 'OUT_FOR_DELIVERY', ($this->at)(2));
        }

        expect($count())->toBe($few);
    });
});

describe('where the customer finds the link', function () {
    it('is on the order page of a signed-in customer and on the confirmation page, only once something has been sent', function () {
        $customer = User::factory()->create();
        $order = ($this->order)([$this->tee => 1, $this->mug => 1]);
        DB::table('orders')->where('id', $order->id)->update(['user_id' => $customer->id]);
        $thanks = URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'en', 'order' => $order->public_id]);

        $this->actingAs($customer, 'web')->get("/en/account/orders/{$order->public_id}")->assertOk()->assertDontSee('Track this order');
        $this->get($thanks)->assertOk()->assertDontSee('Track this order');

        ($this->parcel)($order, [$this->tee => 1]);

        $this->actingAs($customer, 'web')->get("/en/account/orders/{$order->public_id}")->assertOk()->assertSee('Track this order')->assertSee("/orders/{$order->public_id}/track", false)->assertSee('signature=', false);
        $this->get($thanks)->assertOk()->assertSee('Track this order')->assertSee("/orders/{$order->public_id}/track", false);
        $this->actingAs($customer, 'web')->get("/bn/account/orders/{$order->public_id}")->assertOk()->assertSee('এই অর্ডার ট্র্যাক করুন');
    });
});
