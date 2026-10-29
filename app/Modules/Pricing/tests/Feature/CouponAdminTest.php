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
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\Contracts\DiscountEvaluator;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\DTOs\DiscountLine;
use App\Modules\Pricing\DTOs\DiscountRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $actor = Actor::system('t');
    $this->clothing = app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'Clothing'), new TranslationInput('bn', 'পোশাক')]);
    $this->shirts = app(SaveCategory::class)->handle($actor, null, $this->clothing, [new TranslationInput('en', 'Shirts')]);
    $this->product = fn (string $sku, string $en) => app(SaveProduct::class)->handle($actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', $en)], categoryIds: [$this->clothing->id], defaultVariant: new VariantData($sku)));
    $this->tee = ($this->product)('TEE-1', 'Cotton Tee');
    $this->mug = ($this->product)('MUG-1', 'Mug');

    $this->manager = catalogStaff(['promotions.view', 'promotions.manage', 'orders.view']);
    $this->viewer = catalogStaff(['promotions.view']);
    $this->form = fn (array $over = []) => array_replace(['code' => 'save10', 'type' => 'percent', 'value' => '10', 'scope' => 'all', 'is_active' => '1'], $over);
    $this->post = fn (array $over = [], $as = null) => $this->actingAs($as ?? $this->manager, 'admin')->post('/admin/promotions/coupons', ($this->form)($over));
    $this->row = fn (string $code) => DB::table('coupons')->where('code', $code)->first();
    $this->evaluate = fn (array $codes, ?array $lines = null) => app(DiscountEvaluator::class)->evaluate(new DiscountRequest($lines ?? [new DiscountLine(1, $this->tee->id, [$this->clothing->id], 10000)], $codes, null, 'en'));
});

describe('who can do what', function () {
    it('keeps the screens away from anyone without promotions permissions', function () {
        $nobody = catalogStaff(['catalog.view']);

        $this->get('/admin/promotions/coupons')->assertRedirect();
        $this->actingAs($nobody, 'admin')->get('/admin/promotions/coupons')->assertForbidden();
        $this->actingAs($nobody, 'admin')->get('/admin/promotions/automatic')->assertForbidden();
        ($this->post)([], $nobody)->assertForbidden();
    });

    it('lets a viewer look at coupons and their usage but not change them', function () {
        ($this->post)()->assertRedirect();
        $coupon = ($this->row)('SAVE10');

        $this->actingAs($this->viewer, 'admin')->get('/admin/promotions/coupons')->assertOk()->assertSee('SAVE10')->assertDontSee('Add a coupon');
        $this->actingAs($this->viewer, 'admin')->get("/admin/promotions/coupons/{$coupon->public_id}")->assertOk()->assertSee('Times used');
        $this->actingAs($this->viewer, 'admin')->get("/admin/promotions/coupons/{$coupon->public_id}/edit")->assertOk()->assertDontSee('>Save<', false);
        $this->actingAs($this->viewer, 'admin')->put("/admin/promotions/coupons/{$coupon->public_id}", ($this->form)())->assertForbidden();
        ($this->post)(['code' => 'other'], $this->viewer)->assertForbidden();
    });
});

describe('making a coupon', function () {
    it('stores a percentage coupon exactly as typed: code in capitals, percent in basis points, taka in paisa, Dhaka time in UTC', function () {
        ($this->post)([
            'code' => ' save 10 ', 'description' => 'Eid campaign', 'value' => '১০', 'max_discount' => '500', 'min_spend' => '2,000', 'starts_at' => '2026-12-01T10:00', 'ends_at' => '2026-12-31T23:59',
            'usage_limit' => '১০০', 'per_customer_limit' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $c = ($this->row)('SAVE10');
        expect($c->type)->toBe('percent')->and($c->value)->toBe(1000)->and($c->max_discount_minor)->toBe(50000)->and($c->min_spend_minor)->toBe(200000)->and($c->description)->toBe('Eid campaign')
            ->and($c->usage_limit)->toBe(100)->and($c->per_customer_limit)->toBe(1)->and($c->used_count)->toBe(0)->and((bool) $c->is_active)->toBeTrue()
            ->and(substr($c->starts_at, 0, 16))->toBe('2026-12-01 04:00')->and(substr($c->ends_at, 0, 16))->toBe('2026-12-31 17:59')   // Dhaka is 6 hours ahead of UTC
            ->and(DB::table('audit_events')->where('action', 'coupon.created')->count())->toBe(1);
    });

    it('takes decimals in a percentage, fixed amounts in taka, and ignores any value for free delivery', function () {
        ($this->post)(['code' => 'HALF', 'value' => '7.5'])->assertRedirect();
        ($this->post)(['code' => 'FLAT', 'type' => 'fixed', 'value' => '৫০০.৫০'])->assertRedirect();
        ($this->post)(['code' => 'SHIPFREE', 'type' => 'free_shipping', 'value' => '999', 'max_discount' => '5'])->assertRedirect();

        expect(($this->row)('HALF')->value)->toBe(750)->and(($this->row)('FLAT')->value)->toBe(50050)->and(($this->row)('FLAT')->max_discount_minor)->toBeNull()
            ->and(($this->row)('SHIPFREE')->value)->toBe(0)->and(($this->row)('SHIPFREE')->max_discount_minor)->toBeNull();
    });

    it('works at once in the cart: the evaluator uses the new coupon', function () {
        ($this->post)(['code' => 'save10'])->assertRedirect();

        $outcome = ($this->evaluate)(['SAVE10']);
        expect($outcome->acceptedCodes())->toBe(['SAVE10'])->and($outcome->totalMinor())->toBe(1000);
    });

    it('can start in the future and end exactly when set', function () {
        ($this->post)(['code' => 'LATER', 'starts_at' => now('Asia/Dhaka')->addDay()->format('Y-m-d\TH:i')])->assertRedirect();
        ($this->post)(['code' => 'OVER', 'ends_at' => now('Asia/Dhaka')->subMinute()->format('Y-m-d\TH:i')])->assertRedirect();

        expect(($this->evaluate)(['LATER'])->rejected[0]['reason'])->toBe('not_started')->and(($this->evaluate)(['OVER'])->rejected[0]['reason'])->toBe('expired');
    });

    it('limits a coupon to chosen products by SKU, in any case and any separator', function () {
        ($this->post)(['code' => 'TEESONLY', 'scope' => 'products', 'skus' => "tee-1\nMUG-1, tee-1"])->assertRedirect();

        $id = ($this->row)('TEESONLY')->id;
        expect(DB::table('coupon_products')->where('coupon_id', $id)->pluck('product_id')->sort()->values()->all())->toBe(collect([$this->tee->id, $this->mug->id])->sort()->values()->all())
            ->and(($this->evaluate)(['TEESONLY'])->totalMinor())->toBe(1000);
    });

    it('limits a coupon to chosen categories, and a sub-category product is covered by its parent', function () {
        ($this->post)(['code' => 'CLOTHES', 'scope' => 'categories', 'categories' => [(string) $this->clothing->id]])->assertRedirect();

        $id = ($this->row)('CLOTHES')->id;
        expect(DB::table('coupon_categories')->where('coupon_id', $id)->pluck('category_id')->map(fn ($c) => (int) $c)->all())->toBe([$this->clothing->id])
            ->and(($this->evaluate)(['CLOTHES'], [new DiscountLine(1, 1, [$this->shirts->id, $this->clothing->id], 10000)])->totalMinor())->toBe(1000);
    });

    it('explains every mistake and saves nothing', function () {
        $bad = fn (array $over, string $field) => ($this->post)($over)->assertSessionHasErrors($field);

        $bad(['code' => '!!'], 'coupon');
        $bad(['code' => ''], 'code');
        $bad(['value' => '0'], 'value');
        $bad(['value' => '101'], 'value');
        $bad(['value' => 'ten'], 'value');
        $bad(['type' => 'fixed', 'value' => ''], 'coupon');
        $bad(['type' => 'fixed', 'value' => '-5'], 'value');
        $bad(['type' => 'bogus'], 'type');
        $bad(['scope' => 'products', 'skus' => ''], 'coupon');
        $bad(['scope' => 'products', 'skus' => 'NO-SUCH'], 'skus');
        $bad(['scope' => 'categories', 'categories' => [999999]], 'categories');
        $bad(['scope' => 'categories'], 'coupon');
        $bad(['starts_at' => '2026-12-31T00:00', 'ends_at' => '2026-12-01T00:00'], 'coupon');
        $bad(['starts_at' => 'tomorrow'], 'starts_at');
        $bad(['usage_limit' => '0'], 'usage_limit');
        $bad(['min_spend' => 'lots'], 'min_spend');
        $bad(['max_discount' => '0'], 'coupon');
        expect(DB::table('coupons')->count())->toBe(0);
    });

    it('refuses a code that already exists, however it is typed', function () {
        ($this->post)()->assertRedirect();
        ($this->post)(['code' => 'SAVE-10'])->assertRedirect();   // a different code
        ($this->post)(['code' => 'Save10'])->assertSessionHasErrors('coupon');

        expect(DB::table('coupons')->count())->toBe(2);
    });
});

describe('changing a coupon', function () {
    beforeEach(function () {
        ($this->post)(['max_discount' => '500', 'starts_at' => '2026-12-01T10:00', 'usage_limit' => '10'])->assertRedirect();
        $this->coupon = ($this->row)('SAVE10');
        $this->put = fn (array $over = []) => $this->actingAs($this->manager, 'admin')->put("/admin/promotions/coupons/{$this->coupon->public_id}", array_replace(['type' => 'percent', 'value' => '10', 'scope' => 'all', 'is_active' => '1'], $over));
    });

    it('shows the saved values back as typed, including the Dhaka time', function () {
        $this->actingAs($this->manager, 'admin')->get("/admin/promotions/coupons/{$this->coupon->public_id}/edit")->assertOk()
            ->assertSee('value="10"', false)->assertSee('value="500.00"', false)->assertSee('value="2026-12-01T10:00"', false)->assertSee('SAVE10');
    });

    it('edits the details but never the code, and never touches the count of uses', function () {
        DB::table('coupons')->where('id', $this->coupon->id)->update(['used_count' => 4]);

        ($this->put)(['value' => '15', 'usage_limit' => '20'])->assertRedirect()->assertSessionHas('status');
        ($this->put)(['code' => 'RENAMED'])->assertSessionHasErrors('code');

        $c = ($this->row)('SAVE10');
        expect($c->value)->toBe(1500)->and($c->usage_limit)->toBe(20)->and($c->used_count)->toBe(4)->and(DB::table('coupons')->where('code', 'RENAMED')->exists())->toBeFalse()
            ->and(DB::table('audit_events')->where('action', 'coupon.saved')->count())->toBe(1);
    });

    it('will not set the limit below the uses already made', function () {
        DB::table('coupons')->where('id', $this->coupon->id)->update(['used_count' => 4]);

        ($this->put)(['usage_limit' => '3'])->assertSessionHasErrors('coupon');
        expect(($this->row)('SAVE10')->usage_limit)->toBe(10);

        ($this->put)(['usage_limit' => '4'])->assertRedirect();   // equal is fine: used up
        expect(($this->row)('SAVE10')->usage_limit)->toBe(4);
        ($this->put)(['usage_limit' => ''])->assertRedirect();   // no limit
        expect(($this->row)('SAVE10')->usage_limit)->toBeNull();
    });

    it('switches off, and the cart then says it is not active', function () {
        ($this->put)(['is_active' => '0'])->assertRedirect();

        expect(($this->evaluate)(['SAVE10'])->rejected[0]['reason'])->toBe('inactive');
    });

    it('swaps its scope and leaves no leftover targets', function () {
        ($this->put)(['scope' => 'products', 'skus' => 'TEE-1'])->assertRedirect();
        expect(DB::table('coupon_products')->where('coupon_id', $this->coupon->id)->count())->toBe(1);

        ($this->put)(['scope' => 'categories', 'categories' => [(string) $this->clothing->id]])->assertRedirect();
        expect(DB::table('coupon_products')->where('coupon_id', $this->coupon->id)->count())->toBe(0)->and(DB::table('coupon_categories')->where('coupon_id', $this->coupon->id)->count())->toBe(1);

        ($this->put)(['scope' => 'all'])->assertRedirect();
        expect(DB::table('coupon_categories')->where('coupon_id', $this->coupon->id)->count())->toBe(0);
        $this->actingAs($this->manager, 'admin')->put('/admin/promotions/coupons/no-such-coupon', array_diff_key(($this->form)(), ['code' => 1]))->assertNotFound();
    });

    it('lists the SKUs of its products when editing', function () {
        ($this->put)(['scope' => 'products', 'skus' => "TEE-1\nMUG-1"])->assertRedirect();

        $this->actingAs($this->manager, 'admin')->get("/admin/promotions/coupons/{$this->coupon->public_id}/edit")->assertSee('TEE-1')->assertSee('MUG-1');
    });
});

describe('the list and the usage report', function () {
    it('lists coupons with their uses, what they have cost, and whether they work now', function () {
        ($this->post)(['code' => 'LIVE'])->assertRedirect();
        ($this->post)(['code' => 'OFFC', 'is_active' => '0'])->assertRedirect();
        ($this->post)(['code' => 'SOON', 'starts_at' => now('Asia/Dhaka')->addDays(2)->format('Y-m-d\TH:i')])->assertRedirect();
        ($this->post)(['code' => 'DONE', 'usage_limit' => '1'])->assertRedirect();
        DB::table('coupons')->where('code', 'DONE')->update(['used_count' => 1]);
        fakeOrders([1]);
        DB::table('coupon_redemptions')->insert(['coupon_id' => ($this->row)('LIVE')->id, 'order_id' => 1, 'redeemer' => 'u:5', 'amount_minor' => 12345, 'redeemed_at' => now()]);

        $page = $this->actingAs($this->manager, 'admin')->get('/admin/promotions/coupons')->assertOk();
        $page->assertSee('LIVE')->assertSee('123.45')->assertSee('Working')->assertSee('Switched off')->assertSee('Not started')->assertSee('Used up');
        $this->actingAs($this->manager, 'admin')->get('/admin/promotions/coupons?q=soo')->assertSee('SOON')->assertDontSee('LIVE');
    });

    it('shows a coupon\'s usage: totals, and the orders that used it with links', function () {
        ($this->post)(['code' => 'REPORT'])->assertRedirect();
        $coupon = ($this->row)('REPORT');
        fakeOrders([11, 12, 13]);
        DB::table('coupon_redemptions')->insert([
            ['coupon_id' => $coupon->id, 'order_id' => 11, 'redeemer' => 'u:5', 'amount_minor' => 1000, 'redeemed_at' => now()],
            ['coupon_id' => $coupon->id, 'order_id' => 12, 'redeemer' => 'u:5', 'amount_minor' => 2000, 'redeemed_at' => now()],
            ['coupon_id' => $coupon->id, 'order_id' => 13, 'redeemer' => 'p:'.hash('sha256', '+8801712345678'), 'amount_minor' => 500, 'redeemed_at' => now()],
        ]);
        DB::table('coupons')->where('id', $coupon->id)->update(['used_count' => 3]);
        $publicId = (string) DB::table('orders')->where('id', 11)->value('public_id');

        $page = $this->actingAs($this->manager, 'admin')->get("/admin/promotions/coupons/{$coupon->public_id}")->assertOk();
        $page->assertSee('REPORT')->assertSee('35.00')->assertSee('VM-TEST-000011')->assertSee("/admin/orders/{$publicId}", false)->assertSee('Signed-in customer')->assertSee('Guest');
        expect(preg_match('/Different customers.*?>\s*2\s*</s', $page->getContent()))->toBe(1);   // two people (the account used it twice)
        $this->actingAs($this->viewer, 'admin')->get("/admin/promotions/coupons/{$coupon->public_id}")->assertOk()->assertDontSee("/admin/orders/{$publicId}", false)->assertSee('VM-TEST-000011');   // no link without orders.view
    });

    it('says so when a coupon has not been used', function () {
        ($this->post)(['code' => 'NEW'])->assertRedirect();

        $this->actingAs($this->manager, 'admin')->get('/admin/promotions/coupons/'.($this->row)('NEW')->public_id)->assertSee('Not used yet');
    });

    it('answers 404 for a coupon that does not exist', function () {
        $this->actingAs($this->manager, 'admin')->get('/admin/promotions/coupons/nothing-here')->assertNotFound();
        $this->actingAs($this->manager, 'admin')->get('/admin/promotions/coupons/nothing-here/edit')->assertNotFound();
    });
});

describe('automatic offers', function () {
    $form = fn (array $over = []) => array_replace(['type' => 'percent', 'value' => '10', 'min_spend' => '1000', 'is_active' => '1', 'translations' => ['en' => ['label' => '10% off over ৳1,000'], 'bn' => ['label' => '১,০০০ টাকার ওপরে ১০% ছাড়']]], $over);

    it('creates an offer with a label in both languages, and the cart uses it', function () use ($form) {
        $this->actingAs($this->manager, 'admin')->post('/admin/promotions/automatic', $form())->assertRedirect('/admin/promotions/automatic')->assertSessionHas('status');

        $p = DB::table('promotions')->first();
        expect($p->type)->toBe('percent')->and($p->value)->toBe(1000)->and($p->min_spend_minor)->toBe(100000)
            ->and(DB::table('promotion_translations')->where('promotion_id', $p->id)->pluck('label', 'locale')->all())->toEqualCanonicalizing(['en' => '10% off over ৳1,000', 'bn' => '১,০০০ টাকার ওপরে ১০% ছাড়']);

        $outcome = ($this->evaluate)([], [new DiscountLine(1, 1, [], 200000)]);
        expect($outcome->orderDiscountMinor)->toBe(20000)->and($outcome->applied[0]->label)->toBe('10% off over ৳1,000');
        expect(($this->evaluate)([], [new DiscountLine(1, 1, [], 50000)])->orderDiscountMinor)->toBe(0);   // below the minimum
    });

    it('lists, edits, and switches off', function () use ($form) {
        $this->actingAs($this->manager, 'admin')->post('/admin/promotions/automatic', $form());
        $p = DB::table('promotions')->first();

        $this->actingAs($this->manager, 'admin')->get('/admin/promotions/automatic')->assertOk()->assertSee('10% off over')->assertSee('Working');
        $this->actingAs($this->manager, 'admin')->get("/admin/promotions/automatic/{$p->public_id}/edit")->assertOk()->assertSee('value="10"', false)->assertSee('value="1000.00"', false);
        $this->actingAs($this->manager, 'admin')->put("/admin/promotions/automatic/{$p->public_id}", $form(['type' => 'fixed', 'value' => '200', 'is_active' => '0', 'translations' => ['en' => ['label' => '৳200 off'], 'bn' => ['label' => '']]]))->assertRedirect();

        $changed = DB::table('promotions')->where('id', $p->id)->first();
        expect($changed->type)->toBe('fixed')->and($changed->value)->toBe(20000)->and((bool) $changed->is_active)->toBeFalse()->and($changed->max_discount_minor)->toBeNull()
            ->and(DB::table('promotion_translations')->where('promotion_id', $p->id)->pluck('label', 'locale')->all())->toBe(['en' => '৳200 off'])
            ->and(($this->evaluate)([], [new DiscountLine(1, 1, [], 200000)])->orderDiscountMinor)->toBe(0)
            ->and(DB::table('audit_events')->whereIn('action', ['promotion.created', 'promotion.saved'])->count())->toBe(2);
    });

    it('needs permission, an English label, and sensible numbers', function () use ($form) {
        $this->actingAs($this->viewer, 'admin')->post('/admin/promotions/automatic', $form())->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->get('/admin/promotions/automatic')->assertOk()->assertDontSee('Add an offer');

        $post = fn (array $over) => $this->actingAs($this->manager, 'admin')->post('/admin/promotions/automatic', $form($over));
        $post(['translations' => ['en' => ['label' => '']]])->assertSessionHasErrors('translations.en.label');
        $post(['value' => '0'])->assertSessionHasErrors('value');
        $post(['value' => '150'])->assertSessionHasErrors('value');
        $post(['type' => 'free_shipping'])->assertSessionHasErrors('type');
        $post(['starts_at' => '2026-12-31T00:00', 'ends_at' => '2026-12-01T00:00'])->assertSessionHasErrors('promotion');
        $post(['type' => 'fixed', 'value' => ''])->assertSessionHasErrors('value');
        expect(DB::table('promotions')->count())->toBe(0);
    });
});

it('has Bangla wording for everything the coupon and promotion screens say', function () {
    $keys = function (array $a, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
        }

        return $out;
    };

    $en = $keys(require app_path('Modules/Pricing/resources/lang/en/promotions.php'));
    $bn = $keys(require app_path('Modules/Pricing/resources/lang/bn/promotions.php'));
    expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
});
