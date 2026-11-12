<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Platform\Support\SlugRedirects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Finds an active category, brand or collection from a slug the way the storefront does: the slug in the visitor's language, then in any language, then a
 * retired slug that was redirected. An app has no use for a 301, so it simply gets the thing (its payload carries the current slug).
 */
final class SlugLookup
{
    /** @var array<class-string<Model>, array{0: string, 1: string, 2: string}> model => [redirect type, translation table, foreign key] */
    private const KINDS = [
        Category::class => ['category', 'category_translations', 'category_id'],
        Brand::class => ['brand', 'brand_translations', 'brand_id'],
        Collection::class => ['collection', 'collection_translations', 'collection_id'],
    ];

    public function __construct(private readonly SlugRedirects $redirects) {}

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    public function find(string $model, string $slug, string $locale): ?Model
    {
        [$type, $table, $fk] = self::KINDS[$model];
        $default = (string) config('app.default_locale');

        $id = DB::table($table)->where('locale', $locale)->where('slug', $slug)->value($fk)
            ?? DB::table($table)->where('slug', $slug)->orderByRaw('locale = ? DESC', [$default])->value($fk)
            ?? $this->redirects->resolve($type, $locale, $slug);

        /** @var T|null $entity */
        $entity = $id === null ? null : $model::query()->with('translations')->whereKey($id)->where('is_active', true)->first();

        return $entity;
    }
}
