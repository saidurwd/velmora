<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\CheckShippingHealth;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RequestReturn;
use App\Modules\Shipping\Services\ShipmentIssues;
use App\Modules\Shipping\Services\ShippingHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    shippingWorld($this);
    $this->seed([PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Cache::flush();
    $this->viewer = catalogStaff(['shipping.view']);
    $this->at = fn (int $minutes) => now()->addMinutes($minutes)->format('Y-m-d H:i:s');
    $this->made = fn (array $byVariant, bool $handOver = false) => ($this->parcel)(($this->order)($byVariant), $byVariant, $handOver);
    $this->health = fn () => app(ShippingHealth::class);
    $this->breached = fn () => array_keys(($this->health)()->breached());
});

describe('the signals', function () {
    it('is quiet when every delivery is on track', function () {
        $parcel = ($this->made)([$this->mug => 1], true);
        ($this->say)($parcel, 'IN_TRANSIT', ($this->at)(1));

        expect(($this->breached)())->toBe([])->and(array_keys(($this->health)()->snapshot()))->toBe(ShippingHealth::SIGNALS);
    });

    it('raises a courier word we cannot read until someone has dealt with it', function () {
        $parcel = ($this->made)([$this->mug => 1], true);
        ($this->say)($parcel, 'WEIRD_WORD', ($this->at)(1));

        expect(($this->breached)())->toContain('unknown_courier_words');
        DB::table('shipment_exceptions')->where('type', 'unknown_status')->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => 'taught', 'updated_at' => now()]);
        expect(($this->breached)())->not->toContain('unknown_courier_words');
    });

    it('calls a parcel stuck when the courier has said nothing for the set number of days, and not before, and never once it has finished', function () {
        $stuck = ($this->made)([$this->mug => 1], true);
        $done = ($this->made)([$this->tee => 1], true);
        ($this->say)($done, 'DELIVERED', ($this->at)(1));

        $this->travel(6)->days();
        expect(($this->breached)())->not->toContain('stuck_parcels');
        $this->travel(2)->days();   // eight days since the courier last spoke (the handover is the last word on the first parcel)
        expect(($this->breached)())->toContain('stuck_parcels')->and(($this->health)()->snapshot()['stuck_parcels']['value'])->toBe(1);   // the delivered parcel is not counted

        ($this->say)($stuck, 'IN_TRANSIT', now()->format('Y-m-d H:i:s'));   // the courier speaks: no longer stuck
        expect(($this->breached)())->not->toContain('stuck_parcels');
    });

    it('notices a parcel that was made but never handed over', function () {
        $parcel = ($this->made)([$this->mug => 1]);

        $this->travel(47)->hours();
        expect(($this->breached)())->not->toContain('unhanded_parcels');
        $this->travel(2)->hours();
        expect(($this->breached)())->toContain('unhanded_parcels');

        app(HandOverShipment::class)->handle($this->staff, $parcel->public_id);
        expect(($this->breached)())->not->toContain('unhanded_parcels');
    });

    it('notices a delivery problem left open too long, and a return nobody has decided', function () {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $order = ($this->order)([$this->tee => 1]);
        DB::table('orders')->where('id', $order->id)->update(['user_id' => $owner->id]);
        $parcel = ($this->parcel)($order, [$this->tee => 1]);
        ($this->say)($parcel, 'DELIVERED', ($this->at)(1));
        app(RequestReturn::class)->handle(new Actor(ActorType::Customer, $owner->id, $owner->public_id, 'Owner'), $order->public_id, [($this->itemOf)($order->id, $this->tee) => 1], 'damaged', 'Broken');
        $other = ($this->made)([$this->mug => 1], true);
        ($this->say)($other, 'WEIRD_WORD', ($this->at)(1));
        DB::table('shipment_exceptions')->update(['status' => 'ignored', 'resolved_at' => now(), 'resolution' => 'x', 'updated_at' => now()]);   // the unknown word is dealt with
        app(ShipmentIssues::class)->raise('attempts_exhausted', $other->id, $other->public_id.'-x', ['attempts' => 3]);

        $this->travel(47)->hours();
        expect(($this->breached)())->not->toContain('problems_waiting')->and(($this->breached)())->not->toContain('returns_waiting');
        $this->travel(2)->hours();
        expect(($this->breached)())->toContain('problems_waiting')->not->toContain('returns_waiting');   // returns wait 72 hours
        $this->travel(24)->hours();
        expect(($this->breached)())->toContain('returns_waiting');
    });
});

describe('telling a person', function () {
    it('logs each breached signal once an hour, not on every check', function () {
        $parcel = ($this->made)([$this->mug => 1], true);
        ($this->say)($parcel, 'WEIRD_WORD', ($this->at)(1));
        Log::spy();

        $first = app(CheckShippingHealth::class)->handle();
        $second = app(CheckShippingHealth::class)->handle();

        expect($first)->toBe(['unknown_courier_words'])->and($second)->toBe([]);
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c) => $m === 'shipping.alert' && $c['signal'] === 'unknown_courier_words')->once();
    });

    it('is run on a schedule', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toMatch('/\\*\/15 \\* \\* \\* \\*\\s+shipping:check-health/');
    });

    it('shows the banner on the parcel and problem screens only while something is wrong, in both languages', function () {
        $this->get('/admin/shipping/shipments')->assertRedirect();
        $this->actingAs($this->viewer, 'admin')->get('/admin/shipping/shipments')->assertOk()->assertDontSee('Deliveries that need a person');

        $parcel = ($this->made)([$this->mug => 1], true);
        ($this->say)($parcel, 'WEIRD_WORD', ($this->at)(1));
        $this->actingAs($this->viewer, 'admin')->get('/admin/shipping/shipments')->assertOk()->assertSee('Deliveries that need a person')->assertSee('A courier used a status or a parcel we do not know');
        $this->actingAs($this->viewer, 'admin')->get('/admin/shipping/issues')->assertOk()->assertSee('Deliveries that need a person');

        $this->viewer->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->viewer, 'admin')->get('/admin/shipping/shipments')->assertOk()->assertSee('যেসব ডেলিভারিতে একজন মানুষ লাগবে');
    });
});
