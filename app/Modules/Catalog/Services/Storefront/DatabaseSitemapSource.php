<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\Contracts\SitemapSource;
use App\Modules\Catalog\DTOs\SitemapEntry;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

final class DatabaseSitemapSource implements SitemapSource
{
    public function entries(): Generator
    {
        $locales = array_values(array_map('strval', (array) config('app.supported_locales')));

        yield new SitemapEntry(array_combine($locales, array_map(fn ($l) => "/{$l}/shop", $locales)));

        foreach ([['categories', 'category_translations', 'category_id', 'c'], ['brands', 'brand_translations', 'brand_id', 'brands'], ['collections', 'collection_translations', 'collection_id', 'collections']] as [$table, $translations, $fk, $segment]) {
            yield from $this->entities($table, $translations, $fk, $segment, active: true);
        }

        yield from $this->entities('products', 'product_translations', 'product_id', 'p', active: false);
    }

    /** @return Generator<SitemapEntry> */
    private function entities(string $table, string $translations, string $fk, string $segment, bool $active): Generator
    {
        $query = DB::table($table)->whereNull("{$table}.deleted_at");
        $query = $active ? $query->where("{$table}.is_active", true) : $query->where("{$table}.status", 'published')->where(fn ($q) => $q->whereNull("{$table}.publish_at")->orWhere("{$table}.publish_at", '<=', now('UTC')));

        foreach ($query->select("{$table}.id", "{$table}.updated_at")->orderBy("{$table}.id")->lazyById(500, "{$table}.id", 'id') as $row) {
            $paths = [];
            foreach (DB::table($translations)->where($fk, $row->id)->get(['locale', 'slug']) as $t) {
                $paths[(string) $t->locale] = "/{$t->locale}/{$segment}/".rawurlencode((string) $t->slug);
            }

            if ($paths !== []) {
                yield new SitemapEntry($paths, $row->updated_at === null ? null : CarbonImmutable::parse((string) $row->updated_at, 'UTC'));
            }
        }
    }
}
