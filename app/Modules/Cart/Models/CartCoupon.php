<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $cart_id
 * @property string $code
 */
class CartCoupon extends Model
{
    protected $guarded = [];
}
