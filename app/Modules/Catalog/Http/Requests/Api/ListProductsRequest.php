<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Api;

use App\Modules\Catalog\DTOs\ListingFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /products` and `GET /search` queries (API_ARCHITECTURE §1): `page`, `per_page` (1 to 60), `sort`, and `filter[...]`. A wrong value is a 422 that
 * says which field, unlike the website, which quietly ignores what it cannot read.
 *   filter[category]=slug   filter[collection]=slug   filter[brand]=slug,slug   filter[type]=physical,digital   filter[in_stock]=1
 *   filter[price_min]=minor units   filter[price_max]=minor units   filter[attr][color]=red,blue
 */
class ListProductsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 60;

    public function authorize(): bool
    {
        return true; // public catalog
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $slug = ['nullable', 'string', 'max:120', 'regex:/^[\p{L}\p{N}_\-]+$/u'];
        $list = ['nullable', fn (string $a, mixed $v, \Closure $fail) => is_string($v) || is_array($v) ? null : $fail(__('validation.string', ['attribute' => $a]))];

        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort' => ['nullable', Rule::in(ListingFilters::SORTS)],
            'filter' => ['nullable', 'array'],
            'filter.category' => $slug,
            'filter.collection' => $slug,
            'filter.brand' => $list,
            'filter.type' => $list,
            'filter.in_stock' => ['nullable', 'boolean'],
            'filter.price_min' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'filter.price_max' => ['nullable', 'integer', 'min:0', 'max:10000000000', 'gte:filter.price_min'],
            'filter.attr' => ['nullable', 'array', 'max:10'],
            'filter.attr.*' => $list,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function sort(string $default = 'newest'): string
    {
        return (string) ($this->validated('sort') ?? $default);
    }

    public function categorySlug(): ?string
    {
        return $this->validated('filter.category');
    }

    public function collectionSlug(): ?string
    {
        return $this->validated('filter.collection');
    }

    public function filters(string $defaultSort = 'newest'): ListingFilters
    {
        $csv = fn (mixed $v): array => array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), is_array($v) ? $v : explode(',', (string) $v)), fn ($s) => $s !== '')));
        $attributes = [];
        foreach ((array) $this->validated('filter.attr', []) as $code => $values) {
            $codes = $csv($values);
            if ($codes !== []) {
                $attributes[strtolower((string) $code)] = $codes;
            }
        }

        $types = array_values(array_intersect($csv($this->validated('filter.type', [])), ListingFilters::TYPES));
        $min = $this->validated('filter.price_min');
        $max = $this->validated('filter.price_max');

        return new ListingFilters(
            brands: $csv($this->validated('filter.brand', [])),
            minMinor: $min === null ? null : (int) $min,
            maxMinor: $max === null ? null : (int) $max,
            types: $types,
            inStock: (bool) $this->validated('filter.in_stock', false),
            attributes: $attributes,
            sort: $this->sort($defaultSort),
            page: (int) ($this->validated('page') ?? 1),
        );
    }
}
