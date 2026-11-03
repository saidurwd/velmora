<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $variant_id
 * @property int $quantity
 * @property int $added_price_minor
 * @property string|null $hold_ref
 */
class CartItem extends Model
{
    protected $guarded = [];
}
