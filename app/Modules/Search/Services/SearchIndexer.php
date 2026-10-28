<?php

declare(strict_types=1);

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Contracts\SearchableProducts;
use App\Modules\Catalog\DTOs\SearchDocument;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** Keeps `search_index` (normalised text per live product and language) in step with the catalog. */
final class SearchIndexer
{
    private const MAX_BODY = 20000;

    public function __construct(private readonly SearchableProducts $products, private readonly Clock $clock) {}

    public function index(int $productId): void
    {
        $doc = $this->products->document($productId);

        DB::transaction(function () use ($productId, $doc): void {
            DB::table('search_index')->where('product_id', $productId)->delete();
            DB::table('search_skus')->where('product_id', $productId)->delete();

            if ($doc !== null) {
                $this->write($doc);
            }
        });
    }

    /** Rebuilds everything from the catalog (deploys, repairs). */
    public function rebuild(): int
    {
        $live = $this->products->liveProductIds();
        DB::table('search_index')->whereNotIn('product_id', $live)->delete();
        DB::table('search_skus')->whereNotIn('product_id', $live)->delete();
        foreach ($live as $id) {
            $this->index($id);
        }

        return count($live);
    }

    private function write(SearchDocument $doc): void
    {
        $default = (string) config('app.default_locale');
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $fallbackName = $doc->names[$default] ?? (array_values($doc->names)[0] ?? '');
        $skuWords = TextNormalizer::text(implode(' ', $doc->skus));
        $tokens = [];

        foreach ((array) config('app.supported_locales') as $locale) {
            $locale = (string) $locale;
            $name = $doc->names[$locale] ?? $fallbackName;
            $others = array_diff_key($doc->names, [$locale => true]);

            $body = TextNormalizer::text(implode(' ', [
                TextNormalizer::stripHtml($doc->texts[$locale] ?? $doc->texts[$default] ?? ''),
                implode(' ', $doc->related[$locale] ?? $doc->related[$default] ?? []),
                implode(' ', $others), // names in the other languages, so "panjabi" is found from the Bangla site and vice versa
                $skuWords,
            ]));
            $body = mb_substr($body, 0, self::MAX_BODY);
            $title = TextNormalizer::text($name);

            DB::table('search_index')->insert([
                'product_id' => $doc->productId, 'locale' => $locale, 'title' => mb_substr($title, 0, 255), 'body' => $body,
                'display_name' => mb_substr($name, 0, 255), 'slug' => $doc->slugs[$locale] ?? $doc->slugs[$default] ?? '',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach (explode(' ', $title.' '.$body) as $t) {
                if ($t !== '' && mb_strlen($t) >= 3 && mb_strlen($t) <= 40) {
                    $tokens[$t] = true;
                }
            }
        }

        foreach (array_unique(array_map(fn ($s) => str_replace(' ', '', TextNormalizer::text($s)), $doc->skus)) as $sku) {
            if ($sku !== '') {
                DB::table('search_skus')->insertOrIgnore(['product_id' => $doc->productId, 'sku_norm' => mb_substr($sku, 0, 64)]);
            }
        }

        foreach (array_chunk(array_keys($tokens), 500) as $chunk) {
            DB::table('search_terms')->insertOrIgnore(array_map(fn ($t) => ['term' => (string) $t, 'length' => mb_strlen((string) $t)], $chunk));
        }
    }
}
