<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $courier
 * @property string $provider_status
 * @property string $internal_status
 * @property string|null $note
 */
class CourierStatusMapping extends Model
{
    protected $table = 'courier_status_map';

    protected $guarded = [];
}
