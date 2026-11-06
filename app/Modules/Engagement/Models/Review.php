<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int $variant_id
 * @property int $order_item_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $title
 * @property string $body
 * @property string $locale
 * @property string|null $author_name
 * @property string $status
 */
class Review extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];   // written only by the Actions, never from a request
}
