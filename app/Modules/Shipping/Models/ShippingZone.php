<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property bool $is_fallback
 * @property bool $cod_available
 * @property bool $is_active
 */
class ShippingZone extends Model
{
    use HasPublicId, HasTranslations;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_fallback' => 'boolean', 'cod_available' => 'boolean', 'is_active' => 'boolean'];
    }

    protected function translationModel(): string
    {
        return ShippingZoneTranslation::class;
    }

    protected function translationForeignKey(): string
    {
        return 'zone_id';
    }
}
