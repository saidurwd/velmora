<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $transfer_id
 * @property int $variant_id
 * @property int $sent_qty
 * @property int|null $received_qty
 * @property int|null $lost_qty
 */
class StockTransferItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
