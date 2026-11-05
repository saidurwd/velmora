<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $variant_id
 * @property string $status
 * @property string $locale
 */
class StockAlert extends Model
{
    protected $guarded = ['*'];   // written only by the Actions
}
