<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $is_active
 */
class StockLocation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
