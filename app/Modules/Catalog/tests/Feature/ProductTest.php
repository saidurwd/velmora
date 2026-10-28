<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\DeleteVariant;
use App\Modules\Catalog\Actions\GenerateVariants;
use App\Modules\Catalog\Actions\SaveAttribute;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\Actions\SaveVariant;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Jobs\PublishScheduledProducts;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    $this->seed(PriceListSeeder::class);
    $this->actor = Actor::system('test');
    $this->category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'Clothing')]);
});

function simpleVariant(string $sku = 'TEE-001', bool $track = true): VariantData
{
    return new VariantData($sku, trackInventory: $track, weightG: 250);
}

function makeProduct(ProductType $type = ProductType::Physical, ?VariantData $variant = null, array $translations = [], ?Category $category = null, bool $withVariant = true): Product
{
    $product = app(SaveProduct::class)->handle(Actor::system('test'), null, new ProductData(
        $type,
        $translations ?: [new TranslationInput('en', 'Cotton T-Shirt'), new TranslationInput('bn', 'সুতির টি-শার্ট')],
        categoryIds: [($category ?? test()->category)->id],
        defaultVariant: $withVariant ? ($variant ?? simpleVariant(track: $type === ProductType::Physical)) : null,
    ));

    priceAll($product);

    return $product;
}

/** Gives every live variant of the product a price (publishing requires one). */
function priceAll(Product $product, int $minor = 100000): void
{
    foreach (ProductVariant::query()->where('product_id', $product->id)->get() as $variant) {
        app(SetRegularPrice::class)->handle(Actor::system('test'), $variant->id, Money::of($minor));
    }
}

function option(string $code, array $values): Attribute
{
    return app(SaveAttribute::class)->handle(Actor::system('test'), null, $code, 'option', true, ['en' => ucfirst($code), 'bn' => ucfirst($code)],
        array_map(fn ($v) => ['code' => (string) $v, 'labels' => ['en' => ucfirst((string) $v)]], $values));
}

describe('creating products', function () {
    it('creates a simple bilingual product with one default variant', function () {
        $brand = app(SaveTaxonomyEntity::class)->handle($this->actor, Brand::class, null, [new TranslationInput('en', 'Velmora')]);
        $tag = app(SaveTaxonomyEntity::class)->handle($this->actor, Tag::class, null, [new TranslationInput('en', 'Summer')]);

        $product = app(SaveProduct::class)->handle($this->actor, null, new ProductData(
            ProductType::Physical, [new TranslationInput('en', 'Cotton T-Shirt', shortDescription: 'Soft cotton'), new TranslationInput('bn', 'সুতির টি-শার্ট')],
            brandId: $brand->id, categoryIds: [$this->category->id], tagIds: [$tag->id], defaultVariant: new VariantData(' tee-001 ', trackInventory: true), createdBy: 7,
        ));

        $variant = $product->variants()->sole();

        expect($product->public_id)->toMatch('/^[0-9a-z]{26}$/')->and($product->status)->toBe(ProductStatus::Draft)
            ->and($product->translate('name', 'bn'))->toBe('সুতির টি-শার্ট')->and($product->translations->firstWhere('locale', 'en')->short_description)->toBe('Soft cotton')
            ->and($variant->sku)->toBe('TEE-001')->and($variant->is_default)->toBeTrue()->and($variant->option_signature)->toBe('')
            ->and($product->brand_id)->toBe($brand->id)->and($product->tags)->toHaveCount(1)
            ->and(DB::table('product_categories')->where('product_id', $product->id)->value('is_primary'))->toBe(1)
            ->and(DB::table('audit_events')->whereIn('action', ['product.created', 'variant.created'])->count())->toBe(2);
    });

    it('rejects stock tracking on digital and service products, in the action and in the database', function (ProductType $type) {
        expect(fn () => makeProduct($type, simpleVariant(track: true)))->toThrow(CatalogException::class, 'do not track stock');

        $product = makeProduct($type);
        expect(fn () => DB::table('product_variants')->where('product_id', $product->id)->update(['track_inventory' => 1]))->toThrow(QueryException::class, 'Only physical products')
            ->and(fn () => DB::table('product_variants')->insert(['public_id' => str_repeat('b', 26), 'product_id' => $product->id, 'sku' => 'RAW-001', 'track_inventory' => 1, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    })->with([ProductType::Digital, ProductType::Service]);

    it('requires the default language name', function () {
        expect(fn () => makeProduct(translations: [new TranslationInput('bn', 'শুধু বাংলা')]))->toThrow(CatalogException::class, 'default language');
    });

    it('keeps slugs unique per language across products', function () {
        $a = makeProduct();
        $b = makeProduct(variant: simpleVariant('TEE-002'));

        expect($a->translations->firstWhere('locale', 'en')->slug)->toBe('cotton-t-shirt')->and($b->translations->firstWhere('locale', 'en')->slug)->toBe('cotton-t-shirt-2')
            ->and($a->translations->firstWhere('locale', 'bn')->slug)->toBe('সুতির-টি-শার্ট');
    });

    it('soft-deletes a product without losing its data', function () {
        $product = makeProduct();
        $product->delete();

        expect(Product::find($product->id))->toBeNull()->and(Product::withTrashed()->find($product->id))->not->toBeNull()
            ->and(DB::table('product_variants')->where('product_id', $product->id)->count())->toBe(1);
    });

    it('neutralises scripts and unsafe markup in descriptions but keeps safe formatting and Bangla', function () {
        $product = makeProduct(translations: [new TranslationInput('en', 'Safe', description: '<p onclick="steal()">Hello <strong>world</strong></p><script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://example.com/x">ok</a><iframe src="https://evil"></iframe><img src=x onerror=alert(1)>'),
            new TranslationInput('bn', 'নিরাপদ', description: '<p>সুতির কাপড়, <em>আরামদায়ক</em></p><style>body{display:none}</style>')]);

        $en = $product->translations->firstWhere('locale', 'en')->description;
        $bn = $product->translations->firstWhere('locale', 'bn')->description;

        expect($en)->toContain('<strong>world</strong>')->toContain('href="https://example.com/x"')->toContain('rel="nofollow"')
            ->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('<iframe')->not->toContain('onerror')->not->toContain('<img')
            ->and($bn)->toContain('সুতির কাপড়')->toContain('<em>আরামদায়ক</em>')->not->toContain('<style');
    });
});

describe('SKUs', function () {
    it('validates the format', function (string $sku) {
        expect(fn () => makeProduct(variant: new VariantData($sku, trackInventory: true)))->toThrow(CatalogException::class, 'SKU');
    })->with(['ab', '-abc', 'abc-', 'has space', 'bad_underscore', str_repeat('A', 65), '']);

    it('is unique case-insensitively, in the action and in the database', function () {
        makeProduct(variant: simpleVariant('TEE-001'));

        expect(fn () => makeProduct(variant: simpleVariant('tee-001')))->toThrow(CatalogException::class, 'already used');

        $other = makeProduct(variant: simpleVariant('TEE-002'));
        expect(fn () => DB::table('product_variants')->where('product_id', $other->id)->update(['sku' => 'tee-001']))->toThrow(QueryException::class);
    });

    it('never reuses the SKU of a deleted variant', function () {
        $size = option('size', ['s', 'm']);
        $product = makeProduct(withVariant: false);
        $values = $size->values->pluck('id')->all();
        $first = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-EXTRA', trackInventory: true, isDefault: true, optionValueIds: [$values[0]]));
        app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-OTHER', trackInventory: true, optionValueIds: [$values[1]]));
        app(DeleteVariant::class)->handle($this->actor, $first);

        expect(fn () => app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('tee-extra', trackInventory: true, optionValueIds: [$values[0]])))->toThrow(CatalogException::class, 'already used');
    });
});

describe('variants', function () {
    it('keeps exactly one default variant per product, in the database too', function () {
        $size = option('size', ['s', 'm']);
        [$s, $m] = $size->values->pluck('id')->all();
        $product = makeProduct(withVariant: false);
        $first = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-001', trackInventory: true, isDefault: true, optionValueIds: [$s]));
        $second = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-002', trackInventory: true, isDefault: true, optionValueIds: [$m]));

        expect($product->variants()->where('is_default', true)->pluck('id')->all())->toBe([$second->id]); // the action moved the default

        expect(fn () => DB::table('product_variants')->where('id', $first->id)->update(['is_default' => 1]))->toThrow(QueryException::class); // the database refuses a second one
    });

    it('generates size × colour variants with unique SKUs and signatures', function () {
        $size = option('size', ['s', 'm', 'l']);
        $color = option('color', ['red', 'blue']);
        $product = makeProduct(withVariant: false); // option product: no simple variant

        $created = app(GenerateVariants::class)->handle($this->actor, $product, [$size->id => $size->values->pluck('id')->all(), $color->id => $color->values->pluck('id')->all()], 'tee', simpleVariant('X'));

        expect($created)->toHaveCount(6)
            ->and(collect($created)->pluck('sku')->unique())->toHaveCount(6)
            ->and(collect($created)->pluck('sku')->first())->toMatch('/^TEE-(S|M|L)-(RED|BLUE)$/')
            ->and($product->variants()->where('is_default', true)->count())->toBe(1)
            ->and(DB::table('product_options')->where('product_id', $product->id)->count())->toBe(2);
    });

    it('is idempotent and only adds new combinations when a value is added', function () {
        $size = option('size', ['s', 'm']);
        $product = makeProduct(withVariant: false);

        $selection = fn () => [$size->id => $size->fresh()->values->pluck('id')->all()];
        $first = app(GenerateVariants::class)->handle($this->actor, $product, $selection(), 'tee', simpleVariant('X'));
        $again = app(GenerateVariants::class)->handle($this->actor, $product, $selection(), 'tee', simpleVariant('X'));

        app(SaveAttribute::class)->handle($this->actor, $size, 'size', 'option', true, ['en' => 'Size'], [['code' => 'xl', 'labels' => ['en' => 'XL']]]);
        $added = app(GenerateVariants::class)->handle($this->actor, $product, $selection(), 'tee', simpleVariant('X'));

        expect($first)->toHaveCount(2)->and($again)->toHaveCount(0)->and($added)->toHaveCount(1);
    });

    it('refuses an absurd number of combinations', function () {
        $a = option('a', range(1, 16));
        $b = option('b', range(1, 16));
        $product = makeProduct();

        expect(fn () => app(GenerateVariants::class)->handle($this->actor, $product, [$a->id => $a->values->pluck('id')->all(), $b->id => $b->values->pluck('id')->all()], 'x', simpleVariant('X')))
            ->toThrow(CatalogException::class, 'more than');
    });

    it('rejects a spec attribute, two values of one option, and a value from another attribute', function () {
        $material = app(SaveAttribute::class)->handle($this->actor, null, 'material', 'spec', true, ['en' => 'Material'], [['code' => 'cotton', 'labels' => ['en' => 'Cotton']]]);
        $size = option('size', ['s', 'm']);
        $product = makeProduct(withVariant: false);

        $save = fn (array $ids) => app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('NEW-'.uniqid(), trackInventory: true, optionValueIds: $ids));

        expect(fn () => $save([$material->values->first()->id]))->toThrow(CatalogException::class, 'specification')
            ->and(fn () => $save($size->values->pluck('id')->all()))->toThrow(CatalogException::class, 'one value per option')
            ->and(fn () => $save([999999]))->toThrow(CatalogException::class, 'unknown');

        $variant = $save([$size->values->first()->id]);
        $foreign = $material->values->first();
        expect(fn () => DB::table('variant_option_values')->insert(['variant_id' => $variant->id, 'attribute_value_id' => $foreign->id, 'attribute_id' => $size->id]))->toThrow(QueryException::class); // composite FK
    });

    it('rejects two live variants with the same option combination, but allows reuse after deletion', function () {
        $size = option('size', ['s']);
        $product = makeProduct(withVariant: false);
        $v = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('SZ-1', trackInventory: true, isDefault: true, optionValueIds: [$size->values->first()->id]));

        expect(fn () => app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('SZ-2', trackInventory: true, optionValueIds: [$size->values->first()->id])))->toThrow(CatalogException::class, 'already exists');

        app(DeleteVariant::class)->handle($this->actor, $v);
        $again = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('SZ-3', trackInventory: true, isDefault: true, optionValueIds: [$size->values->first()->id]));

        expect($again->exists)->toBeTrue();
    });

    it('promotes another variant to default when the default is deleted, and protects the last live variant', function () {
        $size = option('size', ['s', 'm']);
        [$s, $m] = $size->values->pluck('id')->all();
        $product = makeProduct(withVariant: false);
        $default = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-001', trackInventory: true, isDefault: true, optionValueIds: [$s]));
        $second = app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-002', trackInventory: true, optionValueIds: [$m]));
        priceAll($product);

        app(DeleteVariant::class)->handle($this->actor, $default);
        expect($second->fresh()->is_default)->toBeTrue();

        app(TransitionProduct::class)->publish($this->actor, $product);
        expect(fn () => app(DeleteVariant::class)->handle($this->actor, $second->fresh()))->toThrow(CatalogException::class, 'at least one active variant');
    });

    it('validates quantity rules in the database', function () {
        $product = makeProduct();

        expect(fn () => DB::table('product_variants')->where('product_id', $product->id)->update(['min_qty' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('product_variants')->where('product_id', $product->id)->update(['min_qty' => 5, 'max_qty' => 2]))->toThrow(QueryException::class);
    });
});

describe('mixing simple and option variants', function () {
    it('rejects an option variant next to a simple one, and the reverse', function () {
        $size = option('size', ['s']);
        $simple = makeProduct();

        expect(fn () => app(SaveVariant::class)->handle($this->actor, $simple, null, new VariantData('MIX-1', trackInventory: true, optionValueIds: [$size->values->first()->id])))
            ->toThrow(CatalogException::class, 'same options');

        $withOptions = makeProduct(withVariant: false, variant: null);
        app(SaveVariant::class)->handle($this->actor, $withOptions, null, new VariantData('MIX-2', trackInventory: true, isDefault: true, optionValueIds: [$size->values->first()->id]));

        expect(fn () => app(SaveVariant::class)->handle($this->actor, $withOptions, null, new VariantData('MIX-3', trackInventory: true)))->toThrow(CatalogException::class, 'same options');
    });

    it('explains a duplicate second simple variant in plain words', function () {
        $product = makeProduct();

        expect(fn () => app(SaveVariant::class)->handle($this->actor, $product, null, new VariantData('TEE-002', trackInventory: true)))->toThrow(CatalogException::class, 'already exists');
    });
});

describe('publishing', function () {
    it('publishes a complete product and records when', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);
        $product = makeProduct();

        $published = app(TransitionProduct::class)->publish($this->actor, $product);

        expect($published->status)->toBe(ProductStatus::Published)->and($published->published_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 10:00:00');
    });

    it('refuses to publish an incomplete product', function () {
        $noVariant = makeProduct();
        DB::table('prices')->whereIn('variant_id', $noVariant->variants()->pluck('id'))->delete();
        DB::table('product_variants')->where('product_id', $noVariant->id)->delete();
        $noCategory = makeProduct(variant: simpleVariant('TEE-002'));
        DB::table('product_categories')->where('product_id', $noCategory->id)->delete();
        $noPrice = makeProduct(variant: simpleVariant('TEE-003'));
        DB::table('prices')->where('variant_id', $noPrice->variants()->value('id'))->delete();

        expect(fn () => app(TransitionProduct::class)->publish($this->actor, $noVariant))->toThrow(CatalogException::class, 'active variant')
            ->and(fn () => app(TransitionProduct::class)->publish($this->actor, $noPrice))->toThrow(CatalogException::class, 'needs a price')
            ->and(fn () => app(TransitionProduct::class)->publish($this->actor, $noCategory))->toThrow(CatalogException::class, 'category')
            ->and($noVariant->fresh()->status)->toBe(ProductStatus::Draft);
    });

    it('publishes on schedule through the job, and un-schedules one that became unpublishable', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);
        $ready = makeProduct();
        $broken = makeProduct(variant: simpleVariant('TEE-002'));

        app(TransitionProduct::class)->schedule($this->actor, $ready, $clock->now()->addHour());
        app(TransitionProduct::class)->schedule($this->actor, $broken, $clock->now()->addHour());
        DB::table('product_categories')->where('product_id', $broken->id)->delete(); // breaks after scheduling

        (new PublishScheduledProducts)->handle(app(TransitionProduct::class), $clock, app(Container::class)->make(AuditLogger::class));
        expect($ready->fresh()->status)->toBe(ProductStatus::Draft); // not yet due

        $clock->advanceSeconds(3601);
        (new PublishScheduledProducts)->handle(app(TransitionProduct::class), $clock, app(AuditLogger::class));

        expect($ready->fresh()->status)->toBe(ProductStatus::Published)
            ->and($broken->fresh()->status)->toBe(ProductStatus::Draft)->and($broken->fresh()->publish_at)->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'product.schedule_failed')->count())->toBe(1);
    });

    it('refuses to schedule in the past', function () {
        $clock = new FrozenClock('2026-06-01 10:00:00');
        app()->instance(Clock::class, $clock);

        expect(fn () => app(TransitionProduct::class)->schedule($this->actor, makeProduct(), $clock->now()->subDay()))->toThrow(CatalogException::class, 'future');
    });

    it('unpublishes and archives, and locks the product type once it left draft', function () {
        $product = makeProduct();
        app(TransitionProduct::class)->publish($this->actor, $product);

        expect(fn () => app(SaveProduct::class)->handle($this->actor, $product->fresh(), new ProductData(ProductType::Digital, [new TranslationInput('en', 'X')])))->toThrow(CatalogException::class, 'cannot be changed');

        app(TransitionProduct::class)->unpublish($this->actor, $product);
        expect($product->fresh()->status)->toBe(ProductStatus::Draft);

        app(TransitionProduct::class)->archive($this->actor, $product);
        expect($product->fresh()->status)->toBe(ProductStatus::Archived)
            ->and(DB::table('audit_events')->whereIn('action', ['product.published', 'product.unpublished', 'product.archived'])->count())->toBe(3);
    });

    it('allows changing the type while still a draft', function () {
        $product = makeProduct(ProductType::Digital);

        $changed = app(SaveProduct::class)->handle($this->actor, $product, new ProductData(ProductType::Service, [new TranslationInput('en', 'Now a service')], categoryIds: [$this->category->id]));

        expect($changed->product_type)->toBe(ProductType::Service);
    });
});
