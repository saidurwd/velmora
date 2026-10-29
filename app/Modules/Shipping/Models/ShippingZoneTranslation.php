<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $locale
 * @property string $name
 */
class ShippingZoneTranslation extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
