<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Actions\GenerateVariants;
use App\Modules\Catalog\Actions\SaveAttribute;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Services\Storefront\ListingProjection;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Pricing\Contracts\PriceWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo storefront data for local development and screenshots: 5 bilingual categories, 3 brands, 4 tags, size/colour
 * attributes and 45 published products (simple, with variants, one digital, one service). Re-runnable: every product is
 * keyed by SKU and skipped when it already exists. Never runs in production.
 *
 * Run: php artisan db:seed --class='App\Modules\Catalog\Database\Seeders\DemoCatalogSeeder'
 */
class DemoCatalogSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $collections = [];

    /** @var array<string, array{0: string, 1: string}> slug => [English, Bangla] */
    private const CATEGORIES = [
        'clothing' => ['Clothing', 'পোশাক'],
        'footwear' => ['Footwear', 'জুতা'],
        'accessories' => ['Accessories', 'অ্যাক্সেসরিজ'],
        'home-living' => ['Home & Living', 'ঘর ও জীবনযাপন'],
        'digital-services' => ['Digital & Services', 'ডিজিটাল ও সেবা'],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const BRANDS = ['velmora-basics' => ['Velmora Basics', 'ভেলমোরা বেসিকস'], 'dhaka-craft' => ['Dhaka Craft', 'ঢাকা ক্র্যাফট'], 'padma-home' => ['Padma Home', 'পদ্মা হোম']];

    /** @var array<string, array{0: string, 1: string}> */
    private const COLLECTIONS = ['eid-specials' => ['Eid specials', 'ঈদ স্পেশাল'], 'handmade-picks' => ['Handmade picks', 'হাতে তৈরি বাছাই']];

    /** Tag slug => collection slug: tagged products also join the matching collection. */
    private const TAG_COLLECTION = ['eid' => 'eid-specials', 'handmade' => 'handmade-picks'];

    /** @var array<string, array{0: string, 1: string}> */
    private const TAGS = ['new' => ['New arrival', 'নতুন'], 'eid' => ['Eid special', 'ঈদ বিশেষ'], 'handmade' => ['Handmade', 'হাতে তৈরি'], 'bestseller' => ['Bestseller', 'সেরা বিক্রি']];

    /**
     * Simple physical products: sku => [English, Bangla, category, brand, price ৳, compare-at ৳|null, stock, tag|null].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int|null, 6: int, 7: string|null}>
     */
    private const SIMPLE = [
        'PANJ-001' => ['Cotton Panjabi', 'সুতির পাঞ্জাবি', 'clothing', 'dhaka-craft', 1850, 2200, 35, 'eid'],
        'PANJ-002' => ['Embroidered Panjabi', 'এমব্রয়ডারি পাঞ্জাবি', 'clothing', 'dhaka-craft', 3200, null, 20, 'eid'],
        'SAREE-001' => ['Jamdani Saree', 'জামদানি শাড়ি', 'clothing', 'dhaka-craft', 8500, 9800, 12, 'handmade'],
        'SAREE-002' => ['Tangail Cotton Saree', 'টাঙ্গাইল সুতি শাড়ি', 'clothing', 'dhaka-craft', 2400, null, 30, 'handmade'],
        'KURTI-001' => ['Printed Kurti', 'প্রিন্টেড কুর্তি', 'clothing', 'velmora-basics', 1250, 1500, 40, 'new'],
        'KURTI-002' => ['Linen Kurti', 'লিনেন কুর্তি', 'clothing', 'velmora-basics', 1650, null, 25, null],
        'SHIRT-001' => ['Formal Shirt', 'ফরমাল শার্ট', 'clothing', 'velmora-basics', 1450, null, 50, null],
        'SHIRT-002' => ['Casual Check Shirt', 'ক্যাজুয়াল চেক শার্ট', 'clothing', 'velmora-basics', 1350, 1600, 45, 'bestseller'],
        'LUNGI-001' => ['Cotton Lungi', 'সুতির লুঙ্গি', 'clothing', 'dhaka-craft', 650, null, 80, null],
        'SCARF-001' => ['Silk Scarf', 'সিল্ক স্কার্ফ', 'clothing', 'velmora-basics', 790, null, 60, 'new'],
        'SAND-001' => ['Leather Sandal', 'চামড়ার স্যান্ডেল', 'footwear', 'dhaka-craft', 1950, 2300, 30, 'bestseller'],
        'SAND-002' => ['Kolhapuri Chappal', 'কোলাপুরি চপ্পল', 'footwear', 'dhaka-craft', 1450, null, 28, null],
        'LOAF-001' => ['Suede Loafer', 'সুয়েড লোফার', 'footwear', 'velmora-basics', 3400, null, 18, 'new'],
        'FLIP-001' => ['Rubber Flip-flops', 'রাবারের ফ্লিপ-ফ্লপ', 'footwear', 'velmora-basics', 350, null, 120, null],
        'BOOT-001' => ['Rain Boots', 'রেইন বুট', 'footwear', 'velmora-basics', 1250, null, 22, null],
        'BAG-001' => ['Leather Handbag', 'চামড়ার হ্যান্ডব্যাগ', 'accessories', 'dhaka-craft', 4200, 4800, 15, 'bestseller'],
        'BAG-002' => ['Jute Tote Bag', 'পাটের টোট ব্যাগ', 'accessories', 'padma-home', 590, null, 90, 'handmade'],
        'BAG-003' => ['Canvas Backpack', 'ক্যানভাস ব্যাকপ্যাক', 'accessories', 'velmora-basics', 1850, null, 33, null],
        'WALL-001' => ['Leather Wallet', 'চামড়ার মানিব্যাগ', 'accessories', 'dhaka-craft', 950, null, 55, null],
        'BELT-001' => ['Leather Belt', 'চামড়ার বেল্ট', 'accessories', 'dhaka-craft', 750, null, 48, null],
        'WATCH-001' => ['Classic Wrist Watch', 'ক্লাসিক হাতঘড়ি', 'accessories', 'velmora-basics', 2800, 3200, 20, null],
        'SUNG-001' => ['Polarised Sunglasses', 'পোলারাইজড সানগ্লাস', 'accessories', 'velmora-basics', 1100, null, 42, 'new'],
        'CAP-001' => ['Cotton Cap', 'সুতির ক্যাপ', 'accessories', 'velmora-basics', 390, null, 70, null],
        'JEWEL-001' => ['Terracotta Earrings', 'টেরাকোটা কানের দুল', 'accessories', 'dhaka-craft', 450, null, 65, 'handmade'],
        'JEWEL-002' => ['Silver Plated Necklace', 'রুপালি প্রলেপের হার', 'accessories', 'dhaka-craft', 1650, 1900, 24, 'eid'],
        'BEDS-001' => ['Cotton Bedsheet Set', 'সুতির বিছানার চাদর সেট', 'home-living', 'padma-home', 2100, 2500, 26, 'bestseller'],
        'KANTHA-001' => ['Nakshi Kantha', 'নকশি কাঁথা', 'home-living', 'padma-home', 3600, null, 10, 'handmade'],
        'PILLOW-001' => ['Memory Foam Pillow', 'মেমোরি ফোম বালিশ', 'home-living', 'padma-home', 1150, null, 38, null],
        'CURT-001' => ['Printed Curtain', 'প্রিন্টেড পর্দা', 'home-living', 'padma-home', 1450, null, 29, null],
        'RUG-001' => ['Jute Floor Rug', 'পাটের মেঝে পাপোশ', 'home-living', 'padma-home', 1800, null, 17, 'handmade'],
        'POT-001' => ['Clay Flower Pot', 'মাটির ফুলের টব', 'home-living', 'padma-home', 320, null, 75, 'handmade'],
        'LAMP-001' => ['Brass Table Lamp', 'পিতলের টেবিল ল্যাম্প', 'home-living', 'padma-home', 2300, null, 14, null],
        'MAT-001' => ['Shital Pati Mat', 'শীতল পাটি', 'home-living', 'padma-home', 2700, 3100, 11, 'handmade'],
        'TOWEL-001' => ['Cotton Bath Towel', 'সুতির গোসলের তোয়ালে', 'home-living', 'padma-home', 690, null, 85, null],
        'DISH-001' => ['Ceramic Dinner Set', 'সিরামিক ডিনার সেট', 'home-living', 'padma-home', 4800, 5400, 9, null],
        'CAND-001' => ['Scented Candle', 'সুগন্ধি মোমবাতি', 'home-living', 'padma-home', 420, null, 60, 'new'],
        'BOTTLE-001' => ['Steel Water Bottle', 'স্টিলের পানির বোতল', 'home-living', 'velmora-basics', 650, null, 100, 'bestseller'],
        'BASK-001' => ['Cane Storage Basket', 'বেতের স্টোরেজ ঝুড়ি', 'home-living', 'padma-home', 880, null, 44, 'handmade'],
        'NOTE-001' => ['Handmade Paper Notebook', 'হাতে তৈরি কাগজের নোটবুক', 'accessories', 'dhaka-craft', 280, null, 150, 'handmade'],
    ];

    /**
     * Products with size/colour variants: sku base => [English, Bangla, category, brand, price ৳, option codes by attribute].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: array<string, list<string>>}>
     */
    private const WITH_OPTIONS = [
        'TEE' => ['Crew Neck T-shirt', 'ক্রু নেক টি-শার্ট', 'clothing', 'velmora-basics', 650, ['size' => ['s', 'm', 'l', 'xl'], 'color' => ['black', 'white', 'navy']]],
        'POLO' => ['Pique Polo Shirt', 'পিকে পোলো শার্ট', 'clothing', 'velmora-basics', 950, ['size' => ['m', 'l', 'xl'], 'color' => ['white', 'red']]],
        'HOOD' => ['Fleece Hoodie', 'ফ্লিস হুডি', 'clothing', 'velmora-basics', 1850, ['size' => ['m', 'l', 'xl'], 'color' => ['black', 'navy']]],
        'SNEAK' => ['Canvas Sneakers', 'ক্যানভাস স্নিকার্স', 'footwear', 'velmora-basics', 2250, ['size' => ['s', 'm', 'l'], 'color' => ['white', 'black']]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo catalog is for development only.');
        }

        $actor = Actor::system('demo-seeder');
        $categories = $this->categories($actor);
        $brands = $this->taxonomy($actor, Brand::class, self::BRANDS);
        $tags = $this->taxonomy($actor, Tag::class, self::TAGS);
        $this->collections = $this->taxonomy($actor, Collection::class, self::COLLECTIONS);
        $attributes = $this->attributes($actor);

        foreach (self::SIMPLE as $sku => [$en, $bn, $category, $brand, $price, $compare, $stock, $tag]) {
            if (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
                continue;
            }
            $product = $this->product($actor, ProductType::Physical, $en, $bn, $categories[$category], $brands[$brand], $tag === null ? [] : [$tags[$tag]], new VariantData($sku, trackInventory: true, weightG: 300), isset(self::TAG_COLLECTION[$tag ?? '']) ? [$this->collections[self::TAG_COLLECTION[$tag]]] : []);
            $variantId = (int) $product->variants()->value('id');
            $this->price($actor, $variantId, $price, $compare);
            app(StockInitialiser::class)->openingStock($actor, $variantId, $stock);
            app(TransitionProduct::class)->publish($actor, $product);
        }

        foreach (self::WITH_OPTIONS as $base => [$en, $bn, $category, $brand, $price, $options]) {
            if (ProductVariant::withTrashed()->where('sku', 'like', $base.'-%')->exists()) {
                continue;
            }
            $product = $this->product($actor, ProductType::Physical, $en, $bn, $categories[$category], $brands[$brand], [$tags['bestseller']], null);
            $selection = [];
            foreach ($options as $code => $values) {
                $selection[$attributes[$code]->id] = array_values(array_map('intval', $attributes[$code]->values->whereIn('code', $values)->pluck('id')->all()));
            }
            foreach (app(GenerateVariants::class)->handle($actor, $product, $selection, $base, new VariantData('', trackInventory: true, weightG: 300)) as $variant) {
                $this->price($actor, $variant->id, $price, null);
                app(StockInitialiser::class)->openingStock($actor, $variant->id, 10 + ($variant->id % 15));
            }
            app(TransitionProduct::class)->publish($actor, $product);
        }

        $this->nonPhysical($actor, $categories['digital-services'], $brands['velmora-basics']);
        app(ListingProjection::class)->rebuild();
    }

    private function nonPhysical(Actor $actor, int $category, int $brand): void
    {
        foreach ([
            ['EBOOK-001', ProductType::Digital, 'Bangladeshi Cooking e-book', 'বাংলাদেশি রান্নার ই-বুক', 350],
            ['SVC-ALTER-001', ProductType::Service, 'Tailoring & Alteration Service', 'দর্জি ও কাঁটছাঁট সেবা', 500],
        ] as [$sku, $type, $en, $bn, $price]) {
            if (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
                continue;
            }
            $product = $this->product($actor, $type, $en, $bn, $category, $brand, [], new VariantData($sku));
            $this->price($actor, (int) $product->variants()->value('id'), $price, null);
            app(TransitionProduct::class)->publish($actor, $product);
        }
    }

    /**
     * @param  list<int>  $tagIds
     * @param  list<int>  $collectionIds
     */
    private function product(Actor $actor, ProductType $type, string $en, string $bn, int $categoryId, int $brandId, array $tagIds, ?VariantData $variant, array $collectionIds = []): Product
    {
        return app(SaveProduct::class)->handle($actor, null, new ProductData(
            $type,
            [
                new TranslationInput('en', $en, null, "<p>{$en}: quality you can rely on, made for everyday use.</p>", null, null, "Quality {$en}."),
                new TranslationInput('bn', $bn, null, "<p>{$bn}: দৈনন্দিন ব্যবহারের জন্য নির্ভরযোগ্য মান।</p>", null, null, "মানসম্পন্ন {$bn}।"),
            ],
            $brandId, 'standard', [$categoryId], $categoryId,
            $tagIds, $variant, null, $collectionIds,
        ));
    }

    private function price(Actor $actor, int $variantId, int $taka, ?int $compareTaka): void
    {
        app(PriceWriter::class)->setRegular($actor, $variantId, $taka * 100, $compareTaka === null ? null : $compareTaka * 100);
    }

    /** @return array<string, int> slug => category id */
    private function categories(Actor $actor): array
    {
        $out = [];
        foreach (self::CATEGORIES as $slug => [$en, $bn]) {
            $existing = DB::table('category_translations')->where('locale', 'en')->where('slug', $slug)->value('category_id');
            $out[$slug] = $existing !== null
                ? (int) $existing
                : app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', $en, $slug), new TranslationInput('bn', $bn)], count($out))->id;
        }

        return $out;
    }

    /**
     * @param  class-string<Brand|Tag|Collection>  $model
     * @param  array<string, array{0: string, 1: string}>  $items
     * @return array<string, int> slug => id
     */
    private function taxonomy(Actor $actor, string $model, array $items): array
    {
        $table = (new $model)->translations()->getRelated()->getTable();
        $fk = (new $model)->translations()->getForeignKeyName();
        $out = [];

        foreach ($items as $slug => [$en, $bn]) {
            $existing = DB::table($table)->where('locale', 'en')->where('slug', $slug)->value($fk);
            $out[$slug] = $existing !== null
                ? (int) $existing
                : (int) app(SaveTaxonomyEntity::class)->handle($actor, $model, null, [new TranslationInput('en', $en, $slug), new TranslationInput('bn', $bn)])->id;
        }

        return $out;
    }

    /** @return array<string, Attribute> */
    private function attributes(Actor $actor): array
    {
        $defs = [
            'size' => [['Size', 'সাইজ'], [['s', 'S', 'S'], ['m', 'M', 'M'], ['l', 'L', 'L'], ['xl', 'XL', 'XL']]],
            'color' => [['Colour', 'রং'], [['black', 'Black', 'কালো'], ['white', 'White', 'সাদা'], ['navy', 'Navy', 'নেভি'], ['red', 'Red', 'লাল']]],
        ];
        $out = [];

        foreach ($defs as $code => [[$en, $bn], $values]) {
            $existing = Attribute::query()->where('code', $code)->first();
            $out[$code] = $existing?->load('values') ?? app(SaveAttribute::class)->handle(
                $actor, null, $code, 'option', true, ['en' => $en, 'bn' => $bn],
                array_map(fn ($v) => ['code' => $v[0], 'labels' => ['en' => $v[1], 'bn' => $v[2]]], $values),
            );
        }

        return $out;
    }
}
