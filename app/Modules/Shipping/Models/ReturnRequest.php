<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Shipping\Enums\ReturnStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int|null $shipment_id
 * @property string $kind
 * @property ReturnStatus $status
 * @property string $reason_code
 * @property string|null $reason
 * @property string $requested_by_type
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string $shipping_cost_payer
 * @property int|null $refund_suggested_minor
 * @property string $currency
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $closed_at
 */
class ReturnRequest extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ReturnStatus::class, 'decided_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id')->orderBy('id');
    }
}
