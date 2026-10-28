<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

/** What a listing page is "about": the whole shop, a category subtree, a brand or a collection. */
final readonly class ListingScope
{
    /**
     * @param  list<int>  $categoryIds  the category and all its descendants
     * @param  list<int>|null  $productIds  search results, best first
     */
    private function __construct(public array $categoryIds = [], public ?int $brandId = null, public ?int $collectionId = null, public ?array $productIds = null) {}

    public static function all(): self
    {
        return new self;
    }

    /** @param  list<int>  $subtreeIds */
    public static function category(array $subtreeIds): self
    {
        return new self(categoryIds: $subtreeIds);
    }

    /** @param  list<int>  $ids  search results, best first */
    public static function ids(array $ids): self
    {
        return new self(productIds: $ids);
    }

    public static function brand(int $id): self
    {
        return new self(brandId: $id);
    }

    public static function collection(int $id): self
    {
        return new self(collectionId: $id);
    }
}
