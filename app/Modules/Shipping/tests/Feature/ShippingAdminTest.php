<?php

declare(strict_types=1);

use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Shipping\Contracts\ShippingQuoter;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use App\Modules\Shipping\DTOs\ShippingRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, GeographySeeder::class, ShippingSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->manager = catalogStaff(['shipping.view', 'shipping.manage']);
    $this->viewer = catalogStaff(['shipping.view']);
    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->sylhet = (int) DB::table('districts')->where('name_en', 'Sylhet')->value('id');
    $this->chattogram = (int) DB::table('districts')->where('name_en', 'Chattogram')->value('id') ?: (int) DB::table('districts')->where('name_en', 'Chittagong')->value('id');
    $this->quote = fn (int $district, int $merch = 100000, int $weight = 500) => app(ShippingQuoter::class)->quote(new ShippingRequest($district, $merch, $weight, true, false), 'en');
    $this->zoneOf = fn (string $name) => (object) (array) DB::table('shipping_zones as z')->join('shipping_zone_translations as t', 't.zone_id', '=', 'z.id')->where('t.name', $name)->where('t.locale', 'en')->first(['z.*']);
    $this->zoneForm = fn (array $over = []) => array_replace([
        'translations' => ['en' => ['name' => 'Sylhet region'], 'bn' => ['name' => 'সিলেট অঞ্চল']], 'is_active' => '1', 'cod_available' => '1', 'sort_order' => '3',
        'districts' => [$this->sylhet],
        'rates' => [(string) DB::table('shipping_methods')->value('public_id') => [['weight' => '1', 'price' => '90'], ['weight' => '', 'price' => '150']]],
    ], $over);   // fields in $over replace the defaults whole (no merging of rows)
});

describe('who can do what', function () {
    it('keeps the screens away from anyone without shipping permissions', function () {
        $nobody = catalogStaff(['catalog.view']);

        $this->get('/admin/shipping/zones')->assertRedirect();
        $this->actingAs($nobody, 'admin')->get('/admin/shipping/zones')->assertForbidden();
        $this->actingAs($nobody, 'admin')->get('/admin/shipping/methods')->assertForbidden();
        $this->actingAs($nobody, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)())->assertForbidden();
    });

    it('lets a viewer look but not change anything', function () {
        $zone = (int) DB::table('shipping_zones')->where('is_fallback', true)->value('id');
        $public = (string) DB::table('shipping_zones')->where('id', $zone)->value('public_id');

        $this->actingAs($this->viewer, 'admin')->get('/admin/shipping/zones')->assertOk()->assertSee('Dhaka')->assertDontSee('Add a zone');
        $this->actingAs($this->viewer, 'admin')->get("/admin/shipping/zones/{$public}/edit")->assertOk()->assertDontSee('>Save<', false);
        $this->actingAs($this->viewer, 'admin')->put("/admin/shipping/zones/{$public}", ($this->zoneForm)())->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->post('/admin/shipping/methods', ['code' => 'x'])->assertForbidden();
    });
});

describe('delivery methods', function () {
    it('lists and shows the seeded method in both languages', function () {
        $this->actingAs($this->manager, 'admin')->get('/admin/shipping/methods')->assertOk()->assertSee('Standard delivery')->assertSee('standard');
    });

    it('adds a method with names, delivery time, cash fee and free-over amount, typed in taka (Bangla digits too)', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/methods', [
            'code' => 'express', 'translations' => ['en' => ['name' => 'Express', 'delivery_estimate' => 'Next day'], 'bn' => ['name' => 'এক্সপ্রেস', 'delivery_estimate' => 'পরদিন']],
            'is_active' => '1', 'cod_available' => '1', 'cod_fee' => '৩০', 'free_over' => '5,000.50', 'sort_order' => '২',
        ])->assertRedirect('/admin/shipping/methods')->assertSessionHas('status');

        $m = DB::table('shipping_methods')->where('code', 'express')->first();
        expect($m->cod_fee_minor)->toBe(3000)->and($m->free_over_minor)->toBe(500050)->and($m->sort_order)->toBe(2)->and((bool) $m->is_active)->toBeTrue()
            ->and(DB::table('shipping_method_translations')->where('method_id', $m->id)->pluck('name', 'locale')->all())->toEqualCanonicalizing(['en' => 'Express', 'bn' => 'এক্সপ্রেস'])
            ->and(DB::table('audit_events')->where('action', 'shipping.method.created')->count())->toBe(1);
    });

    it('edits a method but never its code, and a blank Bangla name removes the Bangla text', function () {
        $m = DB::table('shipping_methods')->where('code', 'standard')->first();

        $this->actingAs($this->manager, 'admin')->put("/admin/shipping/methods/{$m->public_id}", ['translations' => ['en' => ['name' => 'Regular'], 'bn' => ['name' => '']], 'cod_available' => '1', 'cod_fee' => '25', 'is_active' => '1', 'sort_order' => '0'])
            ->assertRedirect('/admin/shipping/methods');
        $this->actingAs($this->manager, 'admin')->put("/admin/shipping/methods/{$m->public_id}", ['code' => 'renamed', 'translations' => ['en' => ['name' => 'Regular']]])->assertSessionHasErrors('code');

        expect(DB::table('shipping_methods')->where('id', $m->id)->value('code'))->toBe('standard')->and((int) DB::table('shipping_methods')->where('id', $m->id)->value('cod_fee_minor'))->toBe(2500)
            ->and(DB::table('shipping_method_translations')->where('method_id', $m->id)->pluck('name', 'locale')->all())->toBe(['en' => 'Regular'])
            ->and(DB::table('audit_events')->where('action', 'shipping.method.updated')->count())->toBe(1);
    });

    it('refuses a duplicate or badly formed code, a missing English name, and an amount that is not money', function () {
        $post = fn (array $over) => $this->actingAs($this->manager, 'admin')->post('/admin/shipping/methods', array_replace_recursive(['code' => 'ok', 'translations' => ['en' => ['name' => 'OK']]], $over));

        $post(['code' => 'standard'])->assertSessionHasErrors('code');
        $post(['code' => 'Has Space'])->assertSessionHasErrors('code');
        $post(['translations' => ['en' => ['name' => '']]])->assertSessionHasErrors('translations.en.name');
        $post(['cod_fee' => 'abc'])->assertSessionHasErrors('cod_fee');
        $post(['free_over' => '-5'])->assertSessionHasErrors('free_over');
        $post(['cod_fee' => '1.234'])->assertSessionHasErrors('cod_fee');
        expect(DB::table('shipping_methods')->count())->toBe(1);
    });

    it('takes effect for the next quote at once', function () {
        $m = DB::table('shipping_methods')->where('code', 'standard')->first();
        expect(($this->quote)($this->dhaka)->option('standard')?->codFee->amountMinor)->toBe(0);

        $this->actingAs($this->manager, 'admin')->put("/admin/shipping/methods/{$m->public_id}", ['translations' => ['en' => ['name' => 'Standard delivery']], 'cod_available' => '1', 'cod_fee' => '40', 'free_over' => '500', 'is_active' => '1'])->assertRedirect();

        $option = ($this->quote)($this->dhaka, 100000)->option('standard');
        expect($option?->codFee->amountMinor)->toBe(4000)->and(($this->quote)($this->dhaka, 50000)->option('standard')?->isFree)->toBeTrue();
        $this->actingAs($this->manager, 'admin')->put("/admin/shipping/methods/{$m->public_id}", ['translations' => ['en' => ['name' => 'Standard delivery']], 'cod_available' => '1', 'is_active' => '0']);
        expect(($this->quote)($this->dhaka)->options)->toBe([]);   // switched off: not offered
    });
});

describe('delivery zones', function () {
    it('shows the zones, and warns when nothing covers "everywhere else"', function () {
        $this->actingAs($this->manager, 'admin')->get('/admin/shipping/zones')->assertOk()->assertSee('Dhaka')->assertSee('Rest of Bangladesh')->assertSee('Everywhere else')->assertDontSee('There is no active');

        DB::table('shipping_zones')->update(['is_fallback' => false]);
        $this->actingAs($this->manager, 'admin')->get('/admin/shipping/zones')->assertSee('There is no active');
    });

    it('creates a zone with its districts and prices, and quotes use it straight away', function () {
        expect(($this->quote)($this->sylhet)->option('standard')?->price->amountMinor)->toBe(12000);   // the "rest of Bangladesh" price

        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)())->assertRedirect('/admin/shipping/zones')->assertSessionHas('status');

        $zone = ($this->zoneOf)('Sylhet region');
        expect($zone->cod_available)->toBe(1)->and(DB::table('shipping_zone_districts')->where('zone_id', $zone->id)->pluck('district_id')->map(fn ($d) => (int) $d)->all())->toBe([$this->sylhet])
            ->and(DB::table('shipping_rates')->where('zone_id', $zone->id)->orderByRaw('max_weight_g IS NULL')->get(['max_weight_g', 'price_minor'])->map(fn ($r) => [$r->max_weight_g, $r->price_minor])->all())->toBe([[1000, 9000], [null, 15000]])
            ->and(DB::table('shipping_zone_translations')->where('zone_id', $zone->id)->pluck('name', 'locale')->all())->toEqualCanonicalizing(['en' => 'Sylhet region', 'bn' => 'সিলেট অঞ্চল']);

        expect(($this->quote)($this->sylhet, 100000, 900)->option('standard')?->price->amountMinor)->toBe(9000)->and(($this->quote)($this->sylhet, 100000, 1500)->option('standard')?->price->amountMinor)->toBe(15000)
            ->and(($this->quote)($this->dhaka)->option('standard')?->price->amountMinor)->toBe(6000);
        expect(DB::table('audit_events')->where('action', 'shipping.zone.created')->count())->toBe(1);
    });

    it('shows an existing zone\'s districts and prices on its edit page, in both languages', function () {
        $dhaka = (string) DB::table('shipping_zones')->where('is_fallback', false)->value('public_id');

        $this->actingAs($this->manager, 'admin')->get("/admin/shipping/zones/{$dhaka}/edit")->assertOk()->assertSee('Standard delivery')->assertSee('60.00')->assertSee('Delivery prices')->assertSee('Dhaka');
        $this->actingAs($this->manager, 'admin')->withSession(['locale' => 'bn'])->get("/admin/shipping/zones/{$dhaka}/edit?locale=bn")->assertOk();
    });

    it('moves a district from the zone that had it, and says where it was', function () {
        $dhakaZone = ($this->zoneOf)('Dhaka');
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['districts' => [$this->dhaka]]))->assertRedirect();

        $new = ($this->zoneOf)('Sylhet region');
        expect((int) DB::table('shipping_zone_districts')->where('district_id', $this->dhaka)->value('zone_id'))->toBe($new->id)
            ->and(DB::table('shipping_zone_districts')->where('zone_id', $dhakaZone->id)->count())->toBe(0);

        $this->actingAs($this->manager, 'admin')->get('/admin/shipping/zones/'.$dhakaZone->public_id.'/edit')->assertOk()->assertSee('(now in Sylhet region)');
    });

    it('makes a different zone the "everywhere else" zone, leaving exactly one', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['is_fallback' => '1']))->assertRedirect();

        expect(DB::table('shipping_zones')->where('is_fallback', true)->count())->toBe(1)->and(($this->zoneOf)('Sylhet region')->is_fallback)->toBe(1)
            ->and((int) DB::table('shipping_zones')->where('is_fallback', true)->value('id'))->toBe(($this->zoneOf)('Sylhet region')->id);
    });

    it('will not make the "everywhere else" zone inactive', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['is_fallback' => '1', 'is_active' => '0']))->assertSessionHasErrors('rates');
        expect(DB::table('shipping_zones')->count())->toBe(2);
    });

    it('edits prices: replaces the bands of a method, and removes them with empty rows', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)());
        $zone = ($this->zoneOf)('Sylhet region');
        $method = (string) DB::table('shipping_methods')->value('public_id');

        $this->actingAs($this->manager, 'admin')->put('/admin/shipping/zones/'.$zone->public_id, ($this->zoneForm)(['rates' => [$method => [['weight' => '', 'price' => '200']]]]) + [])->assertRedirect();
        expect(DB::table('shipping_rates')->where('zone_id', $zone->id)->get(['max_weight_g', 'price_minor'])->map(fn ($r) => [$r->max_weight_g, $r->price_minor])->all())->toBe([[null, 20000]]);

        $this->actingAs($this->manager, 'admin')->put('/admin/shipping/zones/'.$zone->public_id, ['translations' => ['en' => ['name' => 'Sylhet region']], 'is_active' => '1', 'rates' => [$method => [['weight' => '', 'price' => '']]]])->assertRedirect();
        expect(DB::table('shipping_rates')->where('zone_id', $zone->id)->count())->toBe(0)->and(DB::table('shipping_zone_districts')->where('zone_id', $zone->id)->count())->toBe(0);
    });

    it('leaves the prices of a method alone when the form does not mention it', function () {
        $dhaka = ($this->zoneOf)('Dhaka');

        $this->actingAs($this->manager, 'admin')->put('/admin/shipping/zones/'.$dhaka->public_id, ['translations' => ['en' => ['name' => 'Dhaka city']], 'is_active' => '1', 'cod_available' => '1', 'districts' => [$this->dhaka]])->assertRedirect();

        expect(DB::table('shipping_rates')->where('zone_id', $dhaka->id)->count())->toBe(1)->and(($this->quote)($this->dhaka)->option('standard')?->price->amountMinor)->toBe(6000);
    });

    it('refuses bad price rows, and keeps everything as it was', function () {
        $method = (string) DB::table('shipping_methods')->value('public_id');
        $post = fn (array $rows) => $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['rates' => [$method => $rows]]));

        $post([['weight' => '', 'price' => '10'], ['weight' => '', 'price' => '20']])->assertSessionHasErrors('rates');          // two "any heavier" rows
        $post([['weight' => '1', 'price' => '10'], ['weight' => '1.000', 'price' => '20']])->assertSessionHasErrors('rates');   // the same weight twice
        $post([['weight' => 'heavy', 'price' => '10']])->assertSessionHasErrors('rates');
        $post([['weight' => '1', 'price' => 'free']])->assertSessionHasErrors('rates');
        $post([['weight' => '0', 'price' => '10']])->assertSessionHasErrors('rates');
        $post([['weight' => '1', 'price' => '-5']])->assertSessionHasErrors('rates');

        expect(DB::table('shipping_zones')->count())->toBe(2)->and(DB::table('shipping_rates')->count())->toBe(2);
    });

    it('refuses an unknown district, a missing English name and an unknown method', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['districts' => [999999]]))->assertSessionHasErrors('districts');
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['translations' => ['en' => ['name' => '']]]))->assertSessionHasErrors('translations.en.name');

        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['rates' => ['no-such-method-id' => [['weight' => '1', 'price' => '10']]]]))->assertRedirect();   // ignored, not an error
        expect(DB::table('shipping_rates')->where('zone_id', ($this->zoneOf)('Sylhet region')->id)->count())->toBe(0);
    });

    it('accepts Bangla digits in weights, prices and the order number', function () {
        $method = (string) DB::table('shipping_methods')->value('public_id');
        $this->actingAs($this->manager, 'admin')->post('/admin/shipping/zones', ($this->zoneForm)(['sort_order' => '৭', 'rates' => [$method => [['weight' => '০.৫', 'price' => '৮০']]]]))->assertRedirect();

        $zone = ($this->zoneOf)('Sylhet region');
        expect($zone->sort_order)->toBe(7)->and(DB::table('shipping_rates')->where('zone_id', $zone->id)->get(['max_weight_g', 'price_minor'])->map(fn ($r) => [$r->max_weight_g, $r->price_minor])->all())->toBe([[500, 8000]]);
    });
});

it('has Bangla wording for everything the shipping screens say', function () {
    $keys = function (array $a, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
        }

        return $out;
    };

    $en = $keys(require app_path('Modules/Shipping/resources/lang/en/admin.php'));
    $bn = $keys(require app_path('Modules/Shipping/resources/lang/bn/admin.php'));
    expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
});
