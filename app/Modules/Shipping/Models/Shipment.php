<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Shipping\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $courier_account_id
 * @property string $courier
 * @property string|null $courier_consignment_id
 * @property string|null $tracking_code
 * @property string|null $tracking_url
 * @property ShipmentStatus $status
 * @property int $cod_amount_minor
 * @property string $currency
 * @property int $attempts
 * @property CarbonImmutable|null $handed_over_at
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class Shipment extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ShipmentStatus::class, 'handed_over_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ShipmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class)->orderBy('id');
    }

    /** @return HasMany<ShipmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
