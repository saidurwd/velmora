<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Catalog\Models\AttributeValue;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Defines an attribute (variant option or spec) with its bilingual name and its allowed values. */
final class SaveAttribute
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /**
     * @param  array<string, string>  $names  locale => name (default language required)
     * @param  list<array{code: string, labels: array<string, string>, swatch?: string|null}>  $values
     */
    public function handle(Actor $actor, ?Attribute $attribute, string $code, string $kind, bool $isFilterable, array $names, array $values): Attribute
    {
        $default = (string) config('app.default_locale');

        if (! in_array($kind, ['option', 'spec'], true)) {
            throw CatalogException::invalidOption('kind must be option or spec');
        }

        if (($names[$default] ?? '') === '') {
            throw CatalogException::defaultLanguageRequired($default);
        }

        return Transactions::run(function () use ($actor, $attribute, $code, $kind, $isFilterable, $names, $values): Attribute {
            $isNew = $attribute === null;
            $attribute ??= new Attribute;
            $attribute->forceFill(['code' => strtolower($code), 'kind' => $kind, 'is_filterable' => $isFilterable])->save();

            DB::table('attribute_translations')->where('attribute_id', $attribute->id)->delete();
            foreach ($names as $locale => $name) {
                if (trim($name) !== '') {
                    DB::table('attribute_translations')->insert(['attribute_id' => $attribute->id, 'locale' => $locale, 'name' => BanglaText::input($name)]);
                }
            }

            foreach ($values as $i => $row) {
                $value = AttributeValue::query()->firstOrCreate(['attribute_id' => $attribute->id, 'code' => strtolower($row['code'])], ['swatch' => $row['swatch'] ?? null, 'sort_order' => $i]);

                DB::table('attribute_value_translations')->where('attribute_value_id', $value->id)->delete();
                foreach ($row['labels'] as $locale => $label) {
                    if (trim($label) !== '') {
                        DB::table('attribute_value_translations')->insert(['attribute_value_id' => $value->id, 'locale' => $locale, 'label' => BanglaText::input($label)]);
                    }
                }
            }

            $this->audit->record($isNew ? 'attribute.created' : 'attribute.updated', 'attribute', after: ['code' => $code, 'kind' => $kind, 'values' => count($values)], actor: $actor, subjectId: $attribute->id);

            $this->cache->bump(); // filter choices on listing pages

            return $attribute->load(['translations', 'values.translations']);
        });
    }
}
