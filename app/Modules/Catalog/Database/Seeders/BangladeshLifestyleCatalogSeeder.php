<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Actions\SaveAttribute;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Services\Storefront\ListingProjection;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The starting catalogue structure of a Bangladeshi lifestyle shop, in English and Bangla: categories (two or three levels), variant options and specifications (colour,
 * size, fabric, occasion, ...), brands, tags and collections. It adds structure only: no products, prices or stock.
 *
 * Safe to run on a live shop and to run again: everything is found by its slug or code and only what is MISSING is added; nothing that exists is changed, renamed or deleted
 * (so edits made in the admin area are kept). Brands other than the shop's own labels are added INACTIVE and collections inactive (see LifestyleTaxonomy).
 *
 * Run: php artisan db:seed --class='Database\Seeders\BangladeshLifestyleSeeder'   (this plus the storefront menus and search spellings)
 */
class BangladeshLifestyleCatalogSeeder extends Seeder
{
    /** @var array<string, int> what was added, by kind */
    public array $added = ['categories' => 0, 'attributes' => 0, 'attribute_values' => 0, 'brands' => 0, 'tags' => 0, 'collections' => 0];

    public function run(): void
    {
        $actor = Actor::system('lifestyle-seeder');

        $this->categories($actor, LifestyleCategories::tree(), null);
        $this->attributes($actor);
        foreach (LifestyleTaxonomy::ownBrands() as $row) {
            $this->taxonomy($actor, Brand::class, $row, true, 'brands');
        }
        foreach (LifestyleTaxonomy::catalogueBrands() as $row) {
            $this->taxonomy($actor, Brand::class, $row, false, 'brands');
        }
        foreach (LifestyleTaxonomy::tags() as $row) {
            $this->taxonomy($actor, Tag::class, $row, true, 'tags');
        }
        foreach (LifestyleTaxonomy::collections() as $row) {
            $this->taxonomy($actor, Collection::class, $row, false, 'collections');
        }
        app(ListingProjection::class)->rebuild();

        $this->command?->info(sprintf('Added: %d categories, %d attributes (%d values), %d brands, %d tags, %d collections.', ...array_values($this->added)));
    }

    /**
     * @param  list<array<int, mixed>>  $nodes
     */
    private function categories(Actor $actor, array $nodes, ?Category $parent): void
    {
        foreach ($nodes as $order => $node) {
            [$slug, $en, $bn] = $node;
            $existingId = DB::table('category_translations')->where('locale', 'en')->where('slug', $slug)->value('category_id');
            $category = $existingId !== null ? Category::query()->findOrFail((int) $existingId) : null;

            if ($category === null) {
                $description = $node[4] ?? null;
                $category = app(SaveCategory::class)->handle($actor, null, $parent, [
                    new TranslationInput('en', $en, $slug, $description[0] ?? null, null, $description[0] ?? null),
                    new TranslationInput('bn', $bn, null, $description[1] ?? null, null, $description[1] ?? null),
                ], $order);
                $this->added['categories']++;
            }

            $this->categories($actor, $node[3] ?? [], $category);
        }
    }

    private function attributes(Actor $actor): void
    {
        foreach (LifestyleAttributes::all() as [$code, $kind, $filterable, $en, $bn, $values]) {
            $rows = array_map(fn (array $v): array => ['code' => $v[0], 'labels' => ['en' => $v[1], 'bn' => $v[2]], 'swatch' => $v[3] ?? null], $values);
            $existing = Attribute::query()->where('code', $code)->first();

            if ($existing === null) {
                app(SaveAttribute::class)->handle($actor, null, $code, $kind, $filterable, ['en' => $en, 'bn' => $bn], $rows);
                $this->added['attributes']++;
                $this->added['attribute_values'] += count($rows);

                continue;
            }

            // it exists (made by hand or by an earlier run): keep its names and values exactly, add only the values it does not have yet
            $have = DB::table('attribute_values')->where('attribute_id', $existing->id)->pluck('code')->all();
            $missing = array_values(array_filter($rows, fn (array $r): bool => ! in_array(strtolower($r['code']), $have, true)));
            if ($missing === []) {
                continue;
            }
            $names = DB::table('attribute_translations')->where('attribute_id', $existing->id)->pluck('name', 'locale')->all();
            app(SaveAttribute::class)->handle($actor, $existing, $code, (string) $existing->kind, (bool) $existing->is_filterable, $names, $missing);
            $this->added['attribute_values'] += count($missing);
        }
    }

    /**
     * @param  class-string<Brand|Tag|Collection>  $model
     * @param  array{0: string, 1: string, 2: string}  $row
     */
    private function taxonomy(Actor $actor, string $model, array $row, bool $active, string $kind): void
    {
        [$slug, $en, $bn] = $row;
        $table = (new $model)->translations()->getRelated()->getTable();
        $fk = (new $model)->translations()->getForeignKeyName();

        if (DB::table($table)->where('locale', 'en')->where('slug', $slug)->exists()) {
            return;
        }

        app(SaveTaxonomyEntity::class)->handle($actor, $model, null, [new TranslationInput('en', $en, $slug), new TranslationInput('bn', $bn)], 0, $active);
        $this->added[$kind]++;
    }
}
