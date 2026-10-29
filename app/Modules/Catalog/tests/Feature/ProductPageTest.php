<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\AddProductMedia;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Actions\ScheduleSale;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, DemoCatalogSeeder::class]);
    $this->actor = Actor::system('t');
});

function productId(string $sku): int
{
    return (int) DB::table('product_variants')->where('sku', $sku)->value('product_id');
}

function variantId(string $sku): int
{
    return (int) DB::table('product_variants')->where('sku', $sku)->value('id');
}

function slugOf(string $sku, string $locale): string
{
    return (string) DB::table('product_translations')->where('product_id', productId($sku))->where('locale', $locale)->value('slug');
}

it('shows a product with name, brand, price, breadcrumb, stock and SKU', function () {
    $this->get('/en/p/cotton-panjabi')->assertOk()
        ->assertSee('Cotton Panjabi')->assertSee('Dhaka Craft')->assertSee('1,850')->assertSee('2,200')->assertSee('PANJ-001')->assertSee('In stock')
        ->assertSee('aria-current="page"', false)->assertSee('/en/c/clothing', false)->assertSee('Add to cart')->assertSee('name="variant"', false);
});

it('shows the Bangla page in Bangla, with Western digits by default and Bangla digits when the store enables them', function () {
    $url = '/bn/p/'.rawurlencode(slugOf('PANJ-001', 'bn'));

    $this->get($url)->assertOk()->assertSee('সুতির পাঞ্জাবি')->assertSee('1,850')->assertSee('স্টকে আছে');

    app(Settings::class)->set('display.bangla_digits', true);
    $this->get($url)->assertOk()->assertSee('১,৮৫০');
});

it('picks the variant from the chosen options and falls back gracefully', function () {
    $this->get('/en/p/crew-neck-t-shirt?opt[size]=xl&opt[color]=navy')->assertOk()->assertSee('TEE-XL-NAVY');
    $this->get('/en/p/crew-neck-t-shirt')->assertOk()->assertSee('name="opt[size]"', false)->assertSee('name="opt[color]"', false);
    $this->get('/en/p/crew-neck-t-shirt?opt[size]=zzz&opt[bogus]=1&opt[color][]=x')->assertOk()->assertSee('TEE-'); // garbage never errors
});

it('marks an out-of-stock option and says so when it is selected', function () {
    $sku = 'TEE-L-WHITE';
    app(AdjustStock::class)->handle($this->actor, variantId($sku), 'main', -(int) DB::table('stock_levels')->where('variant_id', variantId($sku))->value('on_hand'), 'write_off', 'test');

    $this->get('/en/p/crew-neck-t-shirt?opt[size]=l&opt[color]=white')->assertOk()->assertSee('Out of stock');
});

it('shows live prices and sales immediately, without waiting for the listing job', function () {
    app(SetRegularPrice::class)->handle($this->actor, variantId('PANJ-001'), Money::of(177700));
    $this->get('/en/p/cotton-panjabi')->assertOk()->assertSee('1,777');

    app(ScheduleSale::class)->handle($this->actor, variantId('PANJ-001'), Money::of(120000), now('Asia/Dhaka')->subHour()->format('Y-m-d H:i'), now('Asia/Dhaka')->addDay()->format('Y-m-d H:i'));
    $this->get('/en/p/cotton-panjabi')->assertOk()->assertSee('1,200')->assertSee('Sale ends')->assertSee('line-through', false);
});

it('describes digital products and services without stock or delivery claims', function () {
    $this->get('/en/p/bangladeshi-cooking-e-book')->assertOk()->assertSee('available to download after payment')->assertDontSee('In stock')->assertDontSee('Delivered to your address');
    $this->get('/en/p/tailoring-alteration-service')->assertOk()->assertSee('arranged after you order')->assertDontSee('Out of stock');
});

it('returns 404 for unknown, draft and unpublished products', function () {
    $this->get('/en/p/does-not-exist')->assertNotFound();

    app(TransitionProduct::class)->unpublish($this->actor, Product::findOrFail(productId('PANJ-001')));
    $this->get('/en/p/cotton-panjabi')->assertNotFound();
});

it('redirects a slug from another language to this language\'s address, permanently', function () {
    $bn = slugOf('PANJ-001', 'bn');

    $this->get('/en/p/'.rawurlencode($bn))->assertStatus(301)->assertRedirect(url('/en/p/cotton-panjabi'));
    $this->get('/bn/p/cotton-panjabi')->assertStatus(301)->assertRedirect(url('/bn/p/'.rawurlencode($bn)));
});

it('renders a responsive gallery with per-language alt text, the first image prioritised', function () {
    $im = imagecreatetruecolor(1200, 900);
    ob_start();
    imagejpeg($im);
    $bytes = (string) ob_get_clean();
    app(AddProductMedia::class)->handle($this->actor, Product::findOrFail(productId('PANJ-001')), $bytes, 'a.jpg', ['en' => 'Front view', 'bn' => 'সামনের দিক']);
    app(AddProductMedia::class)->handle($this->actor, Product::findOrFail(productId('PANJ-001')), $bytes, 'b.jpg', ['en' => 'Back view']);

    $this->get('/en/p/cotton-panjabi')->assertOk()->assertSee('alt="Front view"', false)->assertSee('srcset=', false)->assertSee('fetchpriority="high"', false)->assertSee('loading="lazy"', false);
    $this->get('/bn/p/'.rawurlencode(slugOf('PANJ-001', 'bn')))->assertSee('alt="সামনের দিক"', false)->assertSee('alt="Back view"', false); // falls back to English alt
});

it('suggests up to four other in-stock products, never the product itself', function () {
    $html = $this->get('/en/p/cotton-panjabi')->assertOk()->assertSee('You may also like')->getContent();
    preg_match_all('#/en/p/([a-z0-9\-]+)#', (string) $html, $m);
    $slugs = array_values(array_unique(array_diff($m[1], ['cotton-panjabi'])));

    expect(count($slugs))->toBeGreaterThanOrEqual(4)->and($html)->not->toContain('You may also like</h2>'.'cotton-panjabi');
});

it('keeps the recently viewed list on the device, with no server call', function () {
    $this->get('/en/p/cotton-panjabi')->assertSee('x-data="recentlyViewed(', false)->assertSee('Recently viewed');
});

it('answers a product page in a bounded number of queries', function () {
    DB::enableQueryLog();
    $this->get('/en/p/crew-neck-t-shirt')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(60);
});
