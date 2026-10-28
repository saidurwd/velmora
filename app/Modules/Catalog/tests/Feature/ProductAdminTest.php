<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(TaxClassSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

function productForm(array $over = []): array
{
    return array_replace_recursive([
        'product_type' => 'physical', 'tax_class_code' => 'standard',
        'translations' => ['en' => ['name' => 'Cotton Shirt'], 'bn' => ['name' => 'সুতির শার্ট']],
        'sku' => 'shirt-001', 'track_inventory' => '1', 'weight_g' => '250',
    ], $over);
}

it('creates a bilingual product with its first variant and lists it', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm())->assertRedirect();

    $product = Product::firstOrFail();
    expect($product->translations)->toHaveCount(2)
        ->and($product->variants)->toHaveCount(1)
        ->and($product->variants[0]->sku)->toBe('SHIRT-001')
        ->and($product->variants[0]->is_default)->toBeTrue();

    $this->actingAs($staff, 'admin')->get('/admin/catalog/products')->assertOk()->assertSee('Cotton Shirt');
    $this->actingAs($staff, 'admin')->get('/admin/catalog/products?q=shirt-0&type=physical&status=draft')->assertOk()->assertSee('Cotton Shirt');
    $this->actingAs($staff, 'admin')->get('/admin/catalog/products?q=nothing')->assertOk()->assertDontSee('Cotton Shirt');
});

it('refuses stock tracking on a digital product and a duplicate SKU, with plain messages', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['product_type' => 'digital']))->assertSessionHasErrors('sku');
    expect(Product::count())->toBe(0);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm())->assertRedirect();
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm())->assertSessionHasErrors('sku');
    expect(Product::count())->toBe(1);
});

it('requires an English name and a known tax class', function () {
    $staff = catalogStaff(['catalog.create']);

    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['translations' => ['en' => ['name' => '']]]))->assertSessionHasErrors('translations.en.name');
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['tax_class_code' => 'made-up']))->assertSessionHasErrors('tax_class_code');
});

it('edits a product and manages variants', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update']);
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm());
    $product = Product::firstOrFail();

    $this->actingAs($staff, 'admin')->get("/admin/catalog/products/{$product->public_id}/edit")->assertOk()->assertSee('SHIRT-001');
    $this->actingAs($staff, 'admin')->put("/admin/catalog/products/{$product->public_id}", ['product_type' => 'physical', 'tax_class_code' => 'standard', 'translations' => ['en' => ['name' => 'Cotton Shirt v2'], 'bn' => ['name' => 'সুতির শার্ট']]])->assertRedirect();
    expect($product->fresh()->translate('name', 'en'))->toBe('Cotton Shirt v2');

    $this->actingAs($staff, 'admin')->post("/admin/catalog/products/{$product->public_id}/variants", ['sku' => 'shirt-002'])->assertSessionHasErrors('variant'); // a second option-less variant is refused

    $this->actingAs($staff, 'admin')->delete("/admin/catalog/products/{$product->public_id}/variants/".$product->variants[0]->public_id)->assertRedirect(); // a draft may lose its only variant (a published one may not: see ProductTest)
    expect(ProductVariant::where('product_id', $product->id)->count())->toBe(0);
});

it('generates variants from option values', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update']);
    $this->actingAs($staff, 'admin')->post('/admin/catalog/attributes', ['code' => 'size', 'kind' => 'option', 'names' => ['en' => 'Size'], 'values' => "s | S\nm | M\nl | L"]);
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['sku' => 'tee']));
    $product = Product::firstOrFail();
    $attrId = DB::table('attributes')->where('code', 'size')->value('id');
    $valueIds = DB::table('attribute_values')->where('attribute_id', $attrId)->pluck('id')->all();

    // product's lone variant has no options; delete-and-regenerate is not allowed, so use a fresh option-based product
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['sku' => 'poloX', 'translations' => ['en' => ['name' => 'Polo']]]));
    $polo = Product::query()->whereHas('translations', fn ($q) => $q->where('name', 'Polo'))->firstOrFail();
    DB::table('product_variants')->where('product_id', $polo->id)->delete();

    $this->actingAs($staff, 'admin')->post("/admin/catalog/products/{$polo->public_id}/variants/generate", ['sku_base' => 'POLO', 'selection' => [$attrId => $valueIds]])->assertRedirect();

    expect(ProductVariant::where('product_id', $polo->id)->count())->toBe(3);
});

it('publishes only a complete product and gates the action by permission', function () {
    $author = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update']);
    $this->actingAs($author, 'admin')->post('/admin/catalog/products', productForm());
    $product = Product::firstOrFail();

    $this->actingAs($author, 'admin')->post("/admin/catalog/products/{$product->public_id}/transition", ['action' => 'publish'])->assertForbidden();

    $publisher = catalogStaff(['catalog.view', 'catalog.publish']);
    $this->actingAs($publisher, 'admin')->post("/admin/catalog/products/{$product->public_id}/transition", ['action' => 'publish'])->assertSessionHasErrors('transition'); // no price / category yet
    expect($product->fresh()->status->value)->toBe('draft');
});

it('keeps the product list free of per-row queries', function () {
    $staff = catalogStaff(['catalog.view', 'catalog.create']);
    foreach (range(1, 15) as $i) {
        $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['sku' => "p-{$i}", 'translations' => ['en' => ['name' => "Item {$i}"]]]));
    }

    DB::enableQueryLog();
    $this->actingAs($staff, 'admin')->get('/admin/catalog/products')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(25);
});

it('forbids product screens without permission', function () {
    $nobody = catalogStaff([]);
    $this->actingAs($nobody, 'admin')->get('/admin/catalog/products')->assertForbidden();
    $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/catalog/products/create')->assertForbidden();
});

it('uploads, reorders, promotes and deletes product images', function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $staff = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update']);
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm());
    $product = Product::firstOrFail();
    $url = "/admin/catalog/products/{$product->public_id}/media";

    $this->actingAs($staff, 'admin')->post($url, ['image' => UploadedFile::fake()->image('a.jpg', 800, 600)])->assertSessionHasErrors('alts.en'); // alt text is required
    $this->actingAs($staff, 'admin')->post($url, ['image' => UploadedFile::fake()->create('x.jpg', 5, 'image/jpeg'), 'alts' => ['en' => 'x']])->assertSessionHasErrors('image');
    $this->actingAs($staff, 'admin')->post($url, ['image' => UploadedFile::fake()->image('a.jpg', 800, 600), 'alts' => ['en' => 'Front', 'bn' => 'সামনে']])->assertRedirect();
    $this->actingAs($staff, 'admin')->post($url, ['image' => UploadedFile::fake()->image('b.jpg', 800, 600), 'alts' => ['en' => 'Back']])->assertRedirect();

    [$first, $second] = $product->media()->get()->all();
    expect($first->is_primary)->toBeTrue();

    $this->actingAs($staff, 'admin')->post("{$url}/{$second->public_id}/primary")->assertRedirect();
    $this->actingAs($staff, 'admin')->post("{$url}/{$second->public_id}/move", ['direction' => 'up'])->assertRedirect();
    expect($product->media()->first()?->id)->toBe($second->id)
        ->and($second->fresh()->is_primary)->toBeTrue();

    $this->actingAs($staff, 'admin')->get("/admin/catalog/products/{$product->public_id}/edit")->assertOk()->assertSee('Front');
    $this->actingAs($staff, 'admin')->delete("{$url}/{$second->public_id}")->assertRedirect();
    expect($product->media()->count())->toBe(1)->and($first->fresh()->is_primary)->toBeTrue();
});

it('does not let one product manage another product\'s image', function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $staff = catalogStaff(['catalog.view', 'catalog.create', 'catalog.update']);
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm());
    $this->actingAs($staff, 'admin')->post('/admin/catalog/products', productForm(['sku' => 'other-1']));
    [$a, $b] = Product::orderBy('id')->get()->all();
    $this->actingAs($staff, 'admin')->post("/admin/catalog/products/{$a->public_id}/media", ['image' => UploadedFile::fake()->image('a.jpg', 800, 600), 'alts' => ['en' => 'A']]);
    $media = $a->media()->firstOrFail();

    $this->actingAs($staff, 'admin')->delete("/admin/catalog/products/{$b->public_id}/media/{$media->public_id}")->assertNotFound();
});
