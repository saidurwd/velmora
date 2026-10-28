<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

/** Plain text of one live product for indexing. Per-language maps are keyed by locale and may lack some languages. */
final readonly class SearchDocument
{
    /**
     * @param  array<string, string>  $names
     * @param  array<string, string>  $slugs
     * @param  array<string, string>  $texts  short + full description, tags stripped
     * @param  array<string, list<string>>  $related  brand, category and tag names per language
     * @param  list<string>  $skus
     */
    public function __construct(public int $productId, public array $names, public array $slugs, public array $texts, public array $related, public array $skus) {}
}
