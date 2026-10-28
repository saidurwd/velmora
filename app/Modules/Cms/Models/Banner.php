<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $placement
 * @property string|null $link_url
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 */
class Banner extends Model
{
    use HasPublicId, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return HasMany<BannerTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(BannerTranslation::class);
    }
}
