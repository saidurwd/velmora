<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use App\Modules\Catalog\Enums\ProductType;

final readonly class ProductData
{
    /**
     * @param  list<TranslationInput>  $translations
     * @param  list<int>  $categoryIds
     * @param  list<int>  $tagIds
     */
    public function __construct(
        public ProductType $type,
        public array $translations,
        public ?int $brandId = null,
        public string $taxClassCode = 'standard',
        public array $categoryIds = [],
        public ?int $primaryCategoryId = null,
        public array $tagIds = [],
        public ?VariantData $defaultVariant = null,
        public ?int $createdBy = null,
    ) {}
}
