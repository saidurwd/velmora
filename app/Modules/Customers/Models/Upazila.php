<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $district_id
 * @property string $name_en
 * @property string $name_bn
 */
class Upazila extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];
}
