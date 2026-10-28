<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\BulkChangePrices;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class, PriceListSeeder::class, TaxClassSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->variantIds = [];
    foreach (['TEE-1' => 100000, 'TEE-2' => 200000] as $sku => $minor) {
        $product = app(SaveProduct::class)->handle(Actor::system('t'), null, new ProductData(ProductType::Physical, [new TranslationInput('en', "Tee {$sku}")], defaultVariant: new VariantData($sku)));
        $id = (int) $product->variants()->value('id');
        app(SetRegularPrice::class)->handle(Actor::system('t'), $id, Money::of($minor));
        $this->variantIds[$sku] = $id;
    }
});

it('finds a variant by SKU and shows its price', function () {
    $staff = catalogStaff(['pricing.view']);

    $this->actingAs($staff, 'admin')->get('/admin/pricing?q=TEE')->assertOk()->assertSee('TEE-1')->assertSee('TEE-2');
    $this->actingAs($staff, 'admin')->get('/admin/pricing/variants/TEE-1')->assertOk()->assertSee('1000.00');
    $this->actingAs($staff, 'admin')->get('/admin/pricing/variants/NOPE')->assertNotFound();
});

it('sets a regular price in taka and rejects bad input in plain words', function () {
    $staff = catalogStaff(['pricing.view', 'pricing.update']);

    $this->actingAs($staff, 'admin')->put('/admin/pricing/variants/TEE-1', ['amount' => '১,২৫০.৫০', 'compare_at' => '1500'])->assertRedirect();
    expect(DB::table('prices')->where('variant_id', $this->variantIds['TEE-1'])->value('amount_minor'))->toBe(125050);

    $this->actingAs($staff, 'admin')->put('/admin/pricing/variants/TEE-1', ['amount' => 'abc'])->assertSessionHasErrors('price');
    $this->actingAs($staff, 'admin')->put('/admin/pricing/variants/TEE-1', ['amount' => '100', 'compare_at' => '50'])->assertSessionHasErrors('price');
});

it('schedules, lists and cancels a sale in Dhaka time', function () {
    $staff = catalogStaff(['pricing.view', 'pricing.update']);
    $start = now('Asia/Dhaka')->addDay()->format('Y-m-d\TH:i');
    $end = now('Asia/Dhaka')->addDays(3)->format('Y-m-d\TH:i');

    $this->actingAs($staff, 'admin')->post('/admin/pricing/variants/TEE-1/sales', ['sale_amount' => '800', 'starts_at' => $start, 'ends_at' => $end])->assertRedirect();
    expect(DB::table('price_sales')->count())->toBe(1);

    $this->actingAs($staff, 'admin')->post('/admin/pricing/variants/TEE-1/sales', ['sale_amount' => '700', 'starts_at' => $start, 'ends_at' => $end])->assertSessionHasErrors('price'); // overlap
    $this->actingAs($staff, 'admin')->post('/admin/pricing/variants/TEE-1/sales', ['sale_amount' => '2000', 'starts_at' => now('Asia/Dhaka')->addDays(10)->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Dhaka')->addDays(11)->format('Y-m-d\TH:i')])->assertSessionHasErrors('price'); // not cheaper

    $this->actingAs($staff, 'admin')->get('/admin/pricing/variants/TEE-1')->assertOk()->assertSee('800');

    $saleId = DB::table('price_sales')->value('id');
    $this->actingAs($staff, 'admin')->delete("/admin/pricing/variants/TEE-2/sales/{$saleId}")->assertNotFound(); // another variant's page cannot cancel it
    $this->actingAs($staff, 'admin')->delete("/admin/pricing/variants/TEE-1/sales/{$saleId}")->assertRedirect();
    expect(DB::table('price_sales')->count())->toBe(0);
});

it('previews a bulk change without writing, then applies it', function () {
    $staff = catalogStaff(['pricing.view', 'pricing.update']);
    $input = ['skus' => "tee-1\nTEE-2\nghost", 'percent' => '-10', 'round' => '100'];

    $this->actingAs($staff, 'admin')->post('/admin/pricing/bulk/preview', $input)->assertOk()->assertSee('TEE-1')->assertSee('TEE-2');
    expect((int) DB::table('prices')->where('variant_id', $this->variantIds['TEE-1'])->value('amount_minor'))->toBe(100000);

    $fingerprint = app(BulkChangePrices::class)->preview(array_values($this->variantIds), -1000, 100)->fingerprint;
    $this->actingAs($staff, 'admin')->post('/admin/pricing/bulk/apply', $input + ['fingerprint' => $fingerprint])->assertRedirect('/admin/pricing/bulk');

    expect((int) DB::table('prices')->where('variant_id', $this->variantIds['TEE-1'])->value('amount_minor'))->toBe(90000)
        ->and((int) DB::table('prices')->where('variant_id', $this->variantIds['TEE-2'])->value('amount_minor'))->toBe(180000);
});

it('refuses to apply a bulk change whose preview is stale', function () {
    $staff = catalogStaff(['pricing.update']);
    $input = ['skus' => 'TEE-1', 'percent' => '10', 'round' => '1'];
    $fingerprint = app(BulkChangePrices::class)->preview([$this->variantIds['TEE-1']], 1000)->fingerprint;

    app(SetRegularPrice::class)->handle(Actor::system('t'), $this->variantIds['TEE-1'], Money::of(120000)); // someone else changes the price

    $this->actingAs($staff, 'admin')->post('/admin/pricing/bulk/apply', $input + ['fingerprint' => $fingerprint])->assertSessionHasErrors('skus');
    expect((int) DB::table('prices')->where('variant_id', $this->variantIds['TEE-1'])->value('amount_minor'))->toBe(120000);
});

it('rejects a nonsense percentage and a list with no known SKU', function () {
    $staff = catalogStaff(['pricing.update']);

    $this->actingAs($staff, 'admin')->post('/admin/pricing/bulk/preview', ['skus' => 'TEE-1', 'percent' => 'lots'])->assertSessionHasErrors('percent');
    $this->actingAs($staff, 'admin')->post('/admin/pricing/bulk/preview', ['skus' => 'ghost', 'percent' => '5'])->assertSessionHasErrors('skus');
});

it('gates pricing screens by permission', function () {
    $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/pricing')->assertForbidden();
    $this->actingAs(catalogStaff(['pricing.view']), 'admin')->put('/admin/pricing/variants/TEE-1', ['amount' => '5'])->assertForbidden();
    $this->actingAs(catalogStaff(['pricing.view']), 'admin')->get('/admin/pricing/bulk')->assertForbidden();
});
