<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Models;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $package_id
 * @property int $resource_id
 * @property int|null $order_item_id
 * @property int|null $user_id
 * @property BookingStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocks_from
 * @property CarbonImmutable $blocks_until
 * @property CarbonImmutable|null $hold_expires_at
 */
class ServiceBooking extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => BookingStatus::class, 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'blocks_from' => 'immutable_datetime', 'blocks_until' => 'immutable_datetime', 'hold_expires_at' => 'immutable_datetime'];
    }
}
