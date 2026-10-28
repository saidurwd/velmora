<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $kind
 * @property bool $is_filterable
 */
class Attribute extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_filterable' => 'boolean'];
    }

    /** @return HasMany<AttributeTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(AttributeTranslation::class);
    }

    /** @return HasMany<AttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    public function label(?string $locale = null): string
    {
        $chain = array_unique([$locale ?? app()->getLocale(), config('app.default_locale')]);
        foreach ($chain as $l) {
            $name = $this->translations->firstWhere('locale', $l)?->name;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $this->code;
    }
}
