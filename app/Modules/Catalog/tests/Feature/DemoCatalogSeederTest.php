<?php

declare(strict_types=1);

use App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class]);
});

it('builds a complete bilingual demo catalog', function () {
    $this->seed(DemoCatalogSeeder::class);

    expect(Product::count())->toBeGreaterThanOrEqual(40)
        ->and(Product::where('status', 'published')->count())->toBe(Product::count())
        ->and(DB::table('categories')->count())->toBe(5)
        ->and(DB::table('product_translations')->where('locale', 'bn')->count())->toBe(Product::count())
        ->and(Product::where('product_type', 'digital')->count())->toBe(1)
        ->and(Product::where('product_type', 'service')->count())->toBe(1)
        ->and(DB::table('product_variants')->count())->toBeGreaterThan(Product::count())   // option products have several variants
        ->and(DB::table('product_variants')->whereNotIn('id', DB::table('prices')->select('variant_id'))->count())->toBe(0)
        ->and(DB::table('product_variants')->where('track_inventory', true)->count())->toBe((int) DB::table('stock_levels')->count())
        ->and(DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')->where('products.product_type', '!=', 'physical')->where('track_inventory', true)->count())->toBe(0);
});

it('can be run again without duplicating anything', function () {
    $this->seed(DemoCatalogSeeder::class);
    $counts = fn () => [Product::count(), DB::table('product_variants')->count(), DB::table('prices')->count(), DB::table('stock_movements')->count(), DB::table('categories')->count()];
    $before = $counts();

    $this->seed(DemoCatalogSeeder::class);

    expect($counts())->toBe($before);
});

it('refuses to run in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new DemoCatalogSeeder)->run())->toThrow(RuntimeException::class);
    expect(Product::count())->toBe(0);
});
