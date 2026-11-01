<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $return_id
 * @property int $order_item_id
 * @property int $quantity
 * @property int|null $received_qty
 * @property string|null $outcome
 * @property int|null $inspected_by
 * @property CarbonImmutable|null $inspected_at
 * @property string|null $note
 */
class ReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['inspected_at' => 'immutable_datetime'];
    }
}
