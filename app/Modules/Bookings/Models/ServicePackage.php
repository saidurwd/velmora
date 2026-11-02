<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $service_id
 * @property int $variant_id
 * @property int $duration_min
 * @property int $buffer_before_min
 * @property int $buffer_after_min
 * @property bool $is_active
 */
class ServicePackage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
