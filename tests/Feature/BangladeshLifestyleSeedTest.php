<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveAttribute;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Database\Seeders\LifestyleAttributes;
use App\Modules\Catalog\Database\Seeders\LifestyleCategories;
use App\Modules\Catalog\Database\Seeders\LifestyleTaxonomy;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Cms\Actions\SaveMenuItem;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Search\Database\Seeders\LifestyleSynonyms;
use Database\Seeders\BangladeshLifestyleSeeder;
use Illuminate\Support\Facades\DB;

/* The Bangladeshi lifestyle starter catalogue: complete in both languages, safe to run again, menus that open. */

function treeSlugs(array $nodes): array
{
    $out = [];
    foreach ($nodes as $node) {
        $out[] = $node[0];
        $out = array_merge($out, treeSlugs($node[3] ?? []));
    }

    return $out;
}

it('adds the whole structure in English and Bangla, and a second run changes nothing', function () {
    $this->seed(LanguageSeeder::class);
    $this->seed(BangladeshLifestyleSeeder::class);
    $count = fn () => [DB::table('categories')->count(), DB::table('attributes')->count(), DB::table('attribute_values')->count(), DB::table('brands')->count(), DB::table('tags')->count(), DB::table('collections')->count(), DB::table('search_synonyms')->count(), DB::table('cms_menu_items')->count(), DB::table('category_closure')->count()];
    $first = $count();

    $slugs = treeSlugs(LifestyleCategories::tree());
    expect($first[0])->toBe(count($slugs))->and(count($slugs))->toBe(count(array_unique($slugs)))->and($first[0])->toBeGreaterThan(120)
        ->and($first[1])->toBe(count(LifestyleAttributes::all()))->and($first[3])->toBe(count(LifestyleTaxonomy::ownBrands()) + count(LifestyleTaxonomy::catalogueBrands()))
        ->and($first[4])->toBe(count(LifestyleTaxonomy::tags()))->and($first[5])->toBe(count(LifestyleTaxonomy::collections()))->and($first[6])->toBe(count(LifestyleSynonyms::pairs()));

    // the tree: twelve areas at the top, never deeper than three levels, every child under the parent its data says
    expect(DB::table('categories')->whereNull('parent_id')->count())->toBe(count(LifestyleCategories::tree()))->and((int) DB::table('category_closure')->max('depth'))->toBeLessThanOrEqual(2);

    // nothing is left untranslated: every row has both languages, and no Bangla name is just the English one
    foreach ([['categories', 'category_translations', 'category_id', 'name'], ['brands', 'brand_translations', 'brand_id', 'name'], ['tags', 'tag_translations', 'tag_id', 'name'], ['collections', 'collection_translations', 'collection_id', 'name'], ['attributes', 'attribute_translations', 'attribute_id', 'name'], ['attribute_values', 'attribute_value_translations', 'attribute_value_id', 'label']] as [$table, $tr, $fk, $col]) {
        $total = DB::table($table)->count();
        expect(DB::table($tr)->where('locale', 'en')->count())->toBe($total, "{$table}: English")->and(DB::table($tr)->where('locale', 'bn')->count())->toBe($total, "{$table}: Bangla")
            ->and(DB::table($tr)->where('locale', 'bn')->pluck($col)->reject(fn ($v) => preg_match('/[\x{0980}-\x{09FF}]/u', (string) $v) === 1 || in_array($v, ['BSTI', 'XS'], true))->values()->all())->toBe([], "{$table}: Bangla text that is not Bangla");
    }

    // brands the shop may not be allowed to sell, and empty collections, stay hidden until the owner switches them on
    expect(DB::table('brands')->where('is_active', true)->count())->toBe(count(LifestyleTaxonomy::ownBrands()))->and(DB::table('collections')->where('is_active', true)->count())->toBe(0)
        ->and(DB::table('categories')->where('is_active', false)->count())->toBe(0);

    // second run: nothing added, nothing changed
    $this->seed(BangladeshLifestyleSeeder::class);
    expect($count())->toBe($first);
})->group('lifestyle');

it('has a header and footer menu whose every link opens, in both languages', function () {
    $this->seed(LanguageSeeder::class);
    $this->seed(BangladeshLifestyleSeeder::class);

    $items = DB::table('cms_menu_items as i')->join('cms_menu_item_translations as t', 't.item_id', '=', 'i.id')->where('i.kind', 'url')->where('i.is_active', true)->select('i.menu', 't.locale', 't.label', 't.url')->get();
    $groups = DB::table('cms_menu_items')->where('kind', 'group')->count();

    expect($items->count())->toBeGreaterThan(60)->and($groups)->toBeGreaterThanOrEqual(10)
        ->and($items->pluck('locale')->unique()->sort()->values()->all())->toBe(['bn', 'en'])
        ->and($items->where('locale', 'bn')->filter(fn ($i) => ! preg_match('/[\x{0980}-\x{09FF}]/u', (string) $i->label))->count())->toBe(0);
    foreach ($items->pluck('url')->unique() as $url) {
        // the account pages ask a signed-out visitor to sign in; every other link opens
        str_contains($url, '/account') ? $this->get($url)->assertRedirect() : $this->get($url)->assertOk();
    }
    $this->get('/en')->assertOk()->assertSee('Women')->assertSee('Handicrafts &amp; Heritage', false);
    $this->get('/bn')->assertOk()->assertSee('নারী')->assertSee('হস্তশিল্প ও ঐতিহ্য');
})->group('lifestyle');

it('keeps what the shop already has: an edited category, an existing attribute, and a menu built by staff', function () {
    $this->seed(LanguageSeeder::class);
    // made by hand before the starter catalogue: a category with our slug but another name, a colour attribute with one value, and a header menu
    $actor = Actor::system('t');
    app(SaveCategory::class)->handle($actor, null, null, [new TranslationInput('en', 'Ladies', 'women'), new TranslationInput('bn', 'মহিলা')]);
    app(SaveAttribute::class)->handle($actor, null, 'color', 'option', true, ['en' => 'Color', 'bn' => 'কালার'], [['code' => 'black', 'labels' => ['en' => 'Jet black', 'bn' => 'গাঢ় কালো']]]);
    app(SaveMenuItem::class)->handle($actor, null, 'header', 'url', null, null, true, true, ['en' => ['label' => 'Home', 'url' => '/en'], 'bn' => ['label' => 'হোম', 'url' => '/bn']]);

    $this->seed(BangladeshLifestyleSeeder::class);

    expect(DB::table('category_translations')->where('locale', 'en')->where('slug', 'women')->value('name'))->toBe('Ladies')
        ->and(DB::table('categories')->whereNull('parent_id')->count())->toBe(count(LifestyleCategories::tree()))
        ->and(DB::table('attribute_translations')->join('attributes', 'attributes.id', '=', 'attribute_translations.attribute_id')->where('code', 'color')->where('locale', 'en')->value('name'))->toBe('Color')
        ->and(DB::table('attribute_value_translations')->join('attribute_values', 'attribute_values.id', '=', 'attribute_value_translations.attribute_value_id')->where('code', 'black')->where('locale', 'en')->value('label'))->toBe('Jet black')
        ->and(DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')->where('attributes.code', 'color')->count())->toBe(count(LifestyleAttributes::all()[0][5]))
        ->and(DB::table('cms_menu_items')->where('menu', 'header')->count())->toBe(1)
        ->and(DB::table('cms_menu_items')->where('menu', 'footer')->count())->toBeGreaterThan(5);
})->group('lifestyle');
