<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Import;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Services\SkuGenerator;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\BanglaText;

/**
 * Validates and normalises one CSV row. Pure: no database access. Slug → id resolution happens in the importer.
 *
 * @phpstan-type Err array{key: string, params: array<string, string>}
 */
final class ProductRowParser
{
    /**
     * @param  list<string>  $taxClasses
     * @param  list<string>  $locales  supported languages (default first)
     */
    public function __construct(private readonly array $taxClasses, private readonly array $locales) {}

    /**
     * @param  array<string, string>  $row
     * @return array{data: array<string, mixed>, errors: list<Err>}
     */
    public function parse(array $row): array
    {
        $errors = [];
        $err = function (string $key, array $params = []) use (&$errors): void {
            $errors[] = ['key' => $key, 'params' => $params];
        };
        $cell = fn (string $k): string => trim($row[$k] ?? '');
        $default = $this->locales[0];

        $sku = strtoupper($cell('sku'));
        if (! SkuGenerator::isValid($sku)) {
            $err('sku_invalid');
        }

        $type = ProductType::tryFrom(strtolower($cell('product_type')));
        if ($type === null) {
            $err('type_invalid');
        }

        $translations = [];
        foreach ($this->locales as $locale) {
            $name = $cell("name_{$locale}");
            $fields = ['name' => $name, 'slug' => $cell("slug_{$locale}"), 'short_description' => $cell("short_description_{$locale}"), 'description' => $cell("description_{$locale}")];
            if ($name !== '' || array_filter($fields) !== []) {
                $translations[$locale] = $fields;
            }
        }
        if (($translations[$default]['name'] ?? '') === '') {
            $err('name_required', ['locale' => strtoupper($default)]);
        }
        foreach ($translations as $locale => $fields) {
            if ($fields['name'] === '' && $locale !== $default) {
                $err('name_required', ['locale' => strtoupper($locale)]); // a description in Bangla without a Bangla name
            }
            if (mb_strlen($fields['name']) > 200) {
                $err('name_too_long');
            }
        }

        $taxClass = strtolower($cell('tax_class')) ?: 'standard';
        if (! in_array($taxClass, $this->taxClasses, true)) {
            $err('tax_class_unknown', ['value' => $taxClass]);
        }

        [$price, $compare] = [null, null];
        try {
            $price = $cell('price') === '' ? null : Money::fromMajorString($cell('price'))->amountMinor;
            $compare = $cell('compare_at') === '' ? null : Money::fromMajorString($cell('compare_at'))->amountMinor;
            if (($price !== null && $price < 0) || ($compare !== null && $compare < 0)) {
                $err('price_invalid');
            } elseif ($compare !== null && $price === null) {
                $err('compare_without_price');
            } elseif ($compare !== null && $compare <= $price) {
                $err('compare_too_low');
            }
        } catch (MoneyException) {
            $err('price_invalid');
        }

        $weight = null;
        if ($cell('weight_g') !== '') {
            $w = BanglaText::numeric($cell('weight_g'));
            if (! ctype_digit($w) || (int) $w > 100_000_000) {
                $err('weight_invalid');
            } else {
                $weight = (int) $w;
            }
        }

        $track = $type === ProductType::Physical;
        if ($cell('track_inventory') !== '') {
            $parsed = match (strtolower($cell('track_inventory'))) {
                '1', 'yes', 'true', 'y', 'হ্যাঁ' => true,
                '0', 'no', 'false', 'n', 'না' => false,
                default => null,
            };
            $parsed === null ? $err('track_invalid') : $track = $parsed;
        }
        if ($track && $type !== null && ! $type->tracksStock()) {
            $err('track_not_allowed', ['type' => $type->value]);
        }

        $opening = null;
        if ($cell('opening_stock') !== '') {
            $q = BanglaText::numeric($cell('opening_stock'));
            if (! ctype_digit($q) || (int) $q > 1_000_000) {
                $err('stock_invalid');
            } else {
                $opening = (int) $q;
                if ($opening > 0 && ! $track) {
                    $err('stock_not_tracked');
                }
            }
        }

        $list = fn (string $k): array => array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim($s)), explode('|', $cell($k))))));

        return [
            'data' => [
                'sku' => $sku, 'type' => $type, 'translations' => $translations, 'tax_class' => $taxClass,
                'categories' => $list('category'), 'tags' => $list('tags'), 'brand' => strtolower($cell('brand')),
                'price' => $price, 'compare_at' => $compare, 'barcode' => $cell('barcode') ?: null,
                'weight_g' => $weight, 'track' => $track, 'opening_stock' => $opening,
            ],
            'errors' => $errors,
        ];
    }
}
