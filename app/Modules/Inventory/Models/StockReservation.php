<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $variant_id
 * @property int $location_id
 * @property int $quantity
 * @property string $owner_type
 * @property string $owner_ref
 * @property string $status
 * @property CarbonImmutable|null $expires_at
 */
class StockReservation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
