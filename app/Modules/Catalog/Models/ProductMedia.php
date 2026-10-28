<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $disk
 * @property string $base_path
 * @property int $width
 * @property int $height
 * @property array<string, array<int|string, string>> $renditions
 * @property bool $is_primary
 * @property bool $is_decorative
 * @property int $sort_order
 */
class ProductMedia extends Model
{
    use HasPublicId, SoftDeletes;

    protected $table = 'product_media';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['renditions' => 'array', 'is_primary' => 'boolean', 'is_decorative' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductMediaTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductMediaTranslation::class, 'media_id');
    }

    /** Alt text in the requested language, falling back to the default language (BD-32). */
    public function alt(?string $locale = null): string
    {
        foreach (array_unique([$locale ?? app()->getLocale(), (string) config('app.default_locale')]) as $l) {
            $alt = $this->translations->firstWhere('locale', $l)?->alt;
            if (is_string($alt) && $alt !== '') {
                return $alt;
            }
        }

        return '';
    }
}
