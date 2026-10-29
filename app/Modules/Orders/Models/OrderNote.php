<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $author_id
 * @property string $visibility
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class OrderNote extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
