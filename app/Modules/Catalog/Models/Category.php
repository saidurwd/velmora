<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $parent_id
 * @property int $sort_order
 * @property bool $is_active
 */
class Category extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function translationModel(): string
    {
        return CategoryTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'category_id';
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
