<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property string $kind
 * @property int $rate_bp
 * @property int $taxable_minor
 * @property int $tax_minor
 */
class OrderTaxLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
