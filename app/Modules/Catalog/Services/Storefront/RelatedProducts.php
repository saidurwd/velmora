<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Storefront;

use App\Modules\Catalog\DTOs\ListingFilters;
use App\Modules\Catalog\DTOs\ListingScope;
use App\Modules\Catalog\DTOs\ProductCard;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Plain rules, no ML: same category first, then same brand, then newest; never the product itself. */
final class RelatedProducts
{
    public function __construct(private readonly ListingQuery $listing, private readonly CategoryTree $tree) {}

    /** @return list<ProductCard> */
    public function for(int $productId, string $locale, int $limit = 4): array
    {
        $cards = [];
        $seen = [$productId];
        $exclude = function () use (&$seen): callable {
            return fn (Builder $q) => $q->whereNotIn('l.product_id', $seen);
        };

        $categoryId = DB::table('product_categories')->where('product_id', $productId)->orderByDesc('is_primary')->value('category_id');
        $brandId = DB::table('products')->where('id', $productId)->value('brand_id');

        $scopes = [];
        if ($categoryId !== null && ($category = Category::query()->whereKey($categoryId)->first()) !== null) {
            $scopes[] = ListingScope::category($this->tree->subtreeIds($category));
        }
        if ($brandId !== null) {
            $scopes[] = ListingScope::brand((int) $brandId);
        }
        $scopes[] = ListingScope::all();

        foreach ($scopes as $scope) {
            if (count($cards) >= $limit) {
                break;
            }
            $found = $this->listing->paginate($scope, new ListingFilters(inStock: true, sort: 'popular'), $locale, $limit - count($cards), $exclude())->items();
            foreach ($found as $card) {
                $cards[] = $card;
                $seen[] = (int) DB::table('products')->where('public_id', $card->publicId)->value('id');
            }
        }

        return $cards;
    }
}
