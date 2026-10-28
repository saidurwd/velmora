<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\DeleteCategory;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveTaxonomyEntity;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    $this->actor = Actor::system('test');
    $this->tree = app(CategoryTree::class);
});

function cat(string $en, ?string $bn = null, ?Category $parent = null, ?Category $existing = null): Category
{
    $inputs = [new TranslationInput('en', $en)];
    if ($bn !== null) {
        $inputs[] = new TranslationInput('bn', $bn);
    }

    return app(SaveCategory::class)->handle(Actor::system('test'), $existing, $parent, $inputs);
}

/** Independent recomputation of the closure from parent_id, to compare with the maintained table. */
function expectedClosure(): array
{
    $parents = DB::table('categories')->pluck('parent_id', 'id')->all();
    $rows = [];
    foreach ($parents as $id => $_) {
        $depth = 0;
        $cursor = $id;
        while (true) {
            $rows[] = "{$cursor}>{$id}:{$depth}";
            $cursor = $parents[$cursor] ?? null;
            if ($cursor === null) {
                break;
            }
            $depth++;
        }
    }
    sort($rows);

    return $rows;
}

function actualClosure(): array
{
    $rows = DB::table('category_closure')->get()->map(fn ($r) => "{$r->ancestor_id}>{$r->descendant_id}:{$r->depth}")->all();
    sort($rows);

    return $rows;
}

describe('category tree', function () {
    it('maintains the closure table when building a hierarchy', function () {
        $men = cat('Men');
        $shirts = cat('Shirts', null, $men);
        $polo = cat('Polo', null, $shirts);

        expect($this->tree->pathIds($polo))->toBe([$men->id, $shirts->id, $polo->id])
            ->and($this->tree->subtreeIds($men))->toEqualCanonicalizing([$men->id, $shirts->id, $polo->id])
            ->and($this->tree->levelOf($polo))->toBe(3)
            ->and(actualClosure())->toBe(expectedClosure());
    });

    it('moves a whole subtree and keeps the closure exact', function () {
        $men = cat('Men');
        $women = cat('Women');
        $tops = cat('Tops', null, $men);
        $tees = cat('Tees', null, $tops);

        cat('Tops', null, $women, $tops); // move Tops (with Tees) under Women

        expect($this->tree->pathIds($tees))->toBe([$women->id, $tops->id, $tees->id])
            ->and($this->tree->subtreeIds($men))->toBe([$men->id])
            ->and(actualClosure())->toBe(expectedClosure());
    });

    it('moves a subtree to the root', function () {
        $a = cat('A');
        $b = cat('B', null, $a);
        $c = cat('C', null, $b);

        cat('B', null, null, $b);

        expect($this->tree->pathIds($c))->toBe([$b->id, $c->id])->and($b->fresh()->parent_id)->toBeNull()
            ->and(actualClosure())->toBe(expectedClosure());
    });

    it('refuses to move a category under itself or its own descendant', function () {
        $a = cat('A');
        $b = cat('B', null, $a);

        expect(fn () => cat('A', null, $b, $a))->toThrow(CatalogException::class, 'cannot be moved under itself')
            ->and(fn () => cat('A', null, $a, $a))->toThrow(CatalogException::class)
            ->and(actualClosure())->toBe(expectedClosure());
    });

    it('limits nesting depth, including when moving a deep subtree', function () {
        $level = cat('L1');
        foreach (range(2, CategoryTree::MAX_DEPTH) as $i) {
            $level = cat("L{$i}", null, $level);
        }

        expect(fn () => cat('TooDeep', null, $level))->toThrow(CatalogException::class, 'at most');

        $other = cat('Other');
        $branch = cat('Branch', null, $other);
        cat('Leaf', null, $branch);

        // moving Branch (height 2) under a level-3 node would reach level 6
        $deepParent = Category::query()->whereHas('translations', fn ($q) => $q->where('name', 'L3'))->firstOrFail();
        expect(fn () => cat('Branch', null, $deepParent, $branch))->toThrow(CatalogException::class);
    });

    it('survives a long random sequence of creates and moves', function () {
        mt_srand(20261028);
        $nodes = [];

        foreach (range(1, 25) as $i) {
            $parent = $nodes === [] || mt_rand(0, 3) === 0 ? null : $nodes[array_rand($nodes)];
            try {
                $nodes[] = cat("Node {$i}", null, $parent);
            } catch (CatalogException) {
            }
        }

        foreach (range(1, 40) as $i) {
            $node = $nodes[array_rand($nodes)];
            $target = mt_rand(0, 4) === 0 ? null : $nodes[array_rand($nodes)];
            try {
                cat('x', null, $target, $node->fresh());
            } catch (CatalogException) {
            }
        }

        expect(actualClosure())->toBe(expectedClosure());
    });

    it('soft-deletes only leaf categories', function () {
        $parent = cat('Parent');
        $child = cat('Child', null, $parent);

        expect(fn () => app(DeleteCategory::class)->handle($this->actor, $parent))->toThrow(CatalogException::class, 'cannot be deleted');

        app(DeleteCategory::class)->handle($this->actor, $child);

        expect(Category::find($child->id))->toBeNull()->and(Category::withTrashed()->find($child->id))->not->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'category.deleted')->count())->toBe(1);
    });

    it('audits create and update', function () {
        $c = cat('Audited');
        cat('Audited renamed', null, null, $c);

        expect(DB::table('audit_events')->whereIn('action', ['category.created', 'category.updated'])->count())->toBe(2);
    });
});

describe('translations', function () {
    it('requires the default language and accepts Bangla as optional', function () {
        expect(fn () => app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('bn', 'শার্ট')]))
            ->toThrow(CatalogException::class, 'default language');

        $c = cat('Shirts', 'শার্ট');

        expect($c->translate('name', 'bn'))->toBe('শার্ট')->and($c->translate('name', 'en'))->toBe('Shirts');
    });

    it('falls back to English when Bangla is missing', function () {
        $c = cat('Shirts');

        expect($c->fresh()->translate('name', 'bn'))->toBe('Shirts')->and($c->fresh()->hasTranslation('name', 'bn'))->toBeFalse();
    });

    it('generates readable slugs in each language', function () {
        $c = cat('Men\'s Shirts', 'পুরুষদের শার্ট');
        $slugs = $c->translations->pluck('slug', 'locale');

        expect($slugs['en'])->toBe('men-s-shirts')->and($slugs['bn'])->toBe('পুরুষদের-শার্ট');
    });

    it('keeps slugs unique per language by adding a suffix, and allows the same slug in different languages', function () {
        $a = cat('Shoes');
        $b = cat('Shoes');
        $c = cat('Shoes');

        expect($a->translations->first()->slug)->toBe('shoes')->and($b->translations->first()->slug)->toBe('shoes-2')->and($c->translations->first()->slug)->toBe('shoes-3');

        $d = cat('Other', 'shoes'); // Bangla slug "shoes" does not clash with the English one
        expect($d->translations->firstWhere('locale', 'bn')->slug)->toBe('shoes');
    });

    it('keeps the same slug when a category is re-saved', function () {
        $c = cat('Shoes');
        cat('Shoes', null, null, $c);

        expect($c->fresh()->translations->first()->slug)->toBe('shoes');
    });

    it('removes a language that is no longer supplied', function () {
        $c = cat('Shirts', 'শার্ট');
        cat('Shirts', null, null, $c);

        expect($c->fresh()->translations->pluck('locale')->all())->toBe(['en']);
    });

    it('rejects unsupported languages', function () {
        expect(fn () => app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'A'), new TranslationInput('fr', 'B')]))
            ->toThrow(CatalogException::class, 'not supported');
    });

    it('enforces uniqueness in the database too', function () {
        $c = cat('Shirts');
        $row = DB::table('category_translations')->where('category_id', $c->id)->first();

        expect(fn () => DB::table('category_translations')->insert(['category_id' => $c->id, 'locale' => 'en', 'name' => 'dup', 'slug' => 'other', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('category_translations')->insert(['category_id' => cat('Another')->id, 'locale' => 'en', 'name' => 'dup', 'slug' => $row->slug, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });

    it('normalises text to NFC and strips control characters on save', function () {
        $c = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', "Tea\x00\x07 cups")]);

        expect($c->fresh()->translate('name', 'en'))->toBe('Tea cups');
    });

    it('does not mix up translations between entity types', function () {
        $category = cat('Same Name');
        $brand = app(SaveTaxonomyEntity::class)->handle($this->actor, Brand::class, null, [new TranslationInput('en', 'Same Name')]);

        expect($category->translations->first()->slug)->toBe('same-name')->and($brand->translations->first()->slug)->toBe('same-name'); // separate tables, separate slug spaces
    });
});

describe('brands, tags and collections', function () {
    it('creates each with bilingual content and audits it', function (string $model, string $action) {
        $entity = app(SaveTaxonomyEntity::class)->handle($this->actor, $model, null, [new TranslationInput('en', 'Velmora Basics'), new TranslationInput('bn', 'ভেলমোরা বেসিকস')], 5, true);

        expect($entity->public_id)->toMatch('/^[0-9a-z]{26}$/')->and($entity->sort_order)->toBe(5)
            ->and($entity->translate('name', 'bn'))->toBe('ভেলমোরা বেসিকস')
            ->and(DB::table('audit_events')->where('action', $action)->count())->toBe(1);
    })->with([[Brand::class, 'brand.created'], [Tag::class, 'tag.created'], [Collection::class, 'collection.created']]);

    it('updates and soft-deletes', function () {
        $brand = app(SaveTaxonomyEntity::class)->handle($this->actor, Brand::class, null, [new TranslationInput('en', 'Old')]);
        app(SaveTaxonomyEntity::class)->handle($this->actor, Brand::class, $brand, [new TranslationInput('en', 'New')], 1, false);

        expect($brand->fresh()->translate('name', 'en'))->toBe('New')->and($brand->fresh()->is_active)->toBeFalse();

        $brand->delete();
        expect(Brand::find($brand->id))->toBeNull()->and(Brand::withTrashed()->find($brand->id))->not->toBeNull();
    });

    it('refuses an unsupported taxonomy class', function () {
        app(SaveTaxonomyEntity::class)->handle($this->actor, stdClass::class, null, [new TranslationInput('en', 'X')]);
    })->throws(InvalidArgumentException::class);
});
