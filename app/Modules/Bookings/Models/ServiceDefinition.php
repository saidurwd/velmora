<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property string $location_type
 * @property int $lead_time_min
 * @property int $max_horizon_days
 * @property int $hold_minutes
 * @property bool $is_active
 */
class ServiceDefinition extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ServicePackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(ServicePackage::class, 'service_id')->orderBy('id');
    }
}
