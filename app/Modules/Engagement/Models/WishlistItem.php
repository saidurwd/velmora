<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $variant_id
 */
class WishlistItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];   // written only by the Actions
}
