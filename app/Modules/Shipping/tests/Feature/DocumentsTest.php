<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shippingWorld($this);
    $this->seed([PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->viewer = catalogStaff(['shipping.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->made = fn (array $byVariant, bool $handOver = false, string $method = 'cod') => ($this->parcel)(($this->order)($byVariant, $method), $byVariant, $handOver);
    $this->as = fn ($staff) => $this->actingAs($staff, 'admin');
});

describe('the packing slip', function () {
    it('carries the business profile once staff have filled it in, and nothing before', function () {
        $order = ($this->order)([$this->tee => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 1], false);
        $url = "/admin/shipping/shipments/{$parcel->public_id}/packing-slip";
        ($this->as)($this->viewer)->get($url)->assertOk()->assertDontSee('TIN:');

        $settings = app(Settings::class);
        $settings->set('business.legal_name.en', 'Velmora Trading');
        $settings->set('business.tin', '123456789012');

        ($this->as)($this->viewer)->get($url)->assertOk()->assertSee('Velmora Trading')->assertSee('TIN: 123456789012');
    });

    it('lists who gets the parcel, where, what is in it and the cash to collect, and shows no price', function () {
        $order = ($this->order)([$this->tee => 2, $this->mug => 1]);
        $parcel = ($this->parcel)($order, [$this->tee => 2], false);

        $html = ($this->as)($this->viewer)->get("/admin/shipping/shipments/{$parcel->public_id}/packing-slip")->assertOk()->assertSee('Packing slip')->assertSee($order->order_number)->assertSee('Rahim Uddin')->assertSee('+8801712345678')
            ->assertSee('House 5, Road 2')->assertSee('Cotton Tee')->assertSee('TEE-1')->assertSee('Cash to collect on delivery')->assertSee('1,300.00')->assertDontSee('Mug')->getContent();

        expect($html)->not->toContain('650.00')->not->toContain('1,730.00');   // not the price of a tee, not the order total
        expect(DB::table('audit_events')->where('action', 'shipment.slip_printed')->count())->toBe(1);
    });

    it('says there is nothing to collect when the order is already paid', function () {
        $parcel = ($this->made)([$this->tee => 1], false, 'online');

        ($this->as)($this->viewer)->get("/admin/shipping/shipments/{$parcel->public_id}/packing-slip")->assertOk()->assertSee('Nothing to collect');
    });

    it('needs the permission to see parcels, and an unknown parcel is not found', function () {
        $parcel = ($this->made)([$this->tee => 1]);
        $url = "/admin/shipping/shipments/{$parcel->public_id}/packing-slip";

        $this->get($url)->assertRedirect();
        ($this->as)($this->nobody)->get($url)->assertForbidden();
        ($this->as)($this->viewer)->get('/admin/shipping/shipments/nope/packing-slip')->assertNotFound();
        ($this->as)($this->viewer)->get("/admin/shipping/shipments/{$parcel->public_id}")->assertOk()->assertSee('Print packing slip');
    });
});

describe('the pick list', function () {
    it('adds up each product over the parcels waiting to be handed over, says which order needs how many, and leaves out the rest', function () {
        $a = ($this->made)([$this->tee => 2, $this->mug => 1]);
        $b = ($this->made)([$this->tee => 3]);
        ($this->made)([$this->mug => 4], true);   // already with the courier

        $html = ($this->as)($this->viewer)->get('/admin/shipping/pick-list')->assertOk()->assertSee('Pick list')->assertSee('TEE-1')->assertSee('MUG-1')->assertSee('2 parcels')->getContent();

        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/>\s*</', '> <', $html))));
        expect($text)->toMatch('/TEE-1 Cotton Tee 5 /')->and($text)->toMatch('/MUG-1 Mug 1 /')->and($text)->not->toContain('650.00')->and($text)->not->toContain('350.00');
        expect(strtoupper(substr($a->public_id, -8)))->toBeString();
    });

    it('says so when nothing is waiting, and uses a fixed number of queries however many parcels there are', function () {
        $this->actingAs($this->viewer, 'admin');
        $this->get('/admin/shipping/pick-list')->assertOk()->assertSee('No parcels are waiting to be handed over.');
        ($this->made)([$this->tee => 1]);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/shipping/pick-list')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->get('/admin/shipping/pick-list');   // warm up
        $few = $count();
        foreach ([$this->mug, $this->tee, $this->mug] as $v) {
            ($this->made)([$v => 1]);
        }
        expect($count())->toBe($few);
    });

    it('needs the permission to see parcels', function () {
        $this->get('/admin/shipping/pick-list')->assertRedirect();
        ($this->as)($this->nobody)->get('/admin/shipping/pick-list')->assertForbidden();
    });
});

describe('both languages', function () {
    it('prints in Bangla with no missing text', function () {
        $this->viewer->forceFill(['locale' => 'bn'])->save();
        $parcel = ($this->made)([$this->tee => 1]);

        foreach (["/admin/shipping/shipments/{$parcel->public_id}/packing-slip", '/admin/shipping/pick-list'] as $page) {
            $html = ($this->as)($this->viewer)->get($page)->assertOk()->getContent();
            expect($html)->not->toContain('shipping::')->not->toContain('fulfilment.');
        }
        ($this->as)($this->viewer)->get("/admin/shipping/shipments/{$parcel->public_id}/packing-slip")->assertSee('প্যাকিং স্লিপ');
        ($this->as)($this->viewer)->get('/admin/shipping/pick-list')->assertSee('পিক লিস্ট');
    });
});
