<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property bool $is_active
 * @property int $sort_order
 */
class Brand extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function translationModel(): string
    {
        return BrandTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'brand_id';
    }
}
