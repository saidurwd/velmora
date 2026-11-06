<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $category
 * @property int $sort_order
 * @property bool $is_published
 */
class FaqEntry extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];   // written only by the Actions

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
