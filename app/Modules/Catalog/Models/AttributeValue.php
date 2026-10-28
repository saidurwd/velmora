<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $code
 * @property string|null $swatch
 */
class AttributeValue extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /** @return HasMany<AttributeValueTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(AttributeValueTranslation::class);
    }

    public function label(?string $locale = null): string
    {
        $chain = array_unique([$locale ?? app()->getLocale(), config('app.default_locale')]);
        foreach ($chain as $l) {
            $label = $this->translations->firstWhere('locale', $l)?->label;
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return $this->code;
    }
}
