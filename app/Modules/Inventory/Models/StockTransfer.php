<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $from_location_id
 * @property int $to_location_id
 * @property TransferStatus $status
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $cancelled_at
 */
class StockTransfer extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TransferStatus::class, 'received_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id')->orderBy('id');
    }
}
