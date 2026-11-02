<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $variant_id
 * @property int|null $download_limit
 * @property int|null $access_days
 * @property int|null $created_by
 */
class DigitalAsset extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return HasMany<DigitalAssetVersion, $this> newest first */
    public function versions(): HasMany
    {
        return $this->hasMany(DigitalAssetVersion::class, 'asset_id')->orderByDesc('version');
    }
}
