<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $count_id
 * @property int $variant_id
 * @property int $expected_qty
 * @property int|null $counted_qty
 * @property int|null $system_qty
 * @property int|null $variance
 */
class StockCountLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
