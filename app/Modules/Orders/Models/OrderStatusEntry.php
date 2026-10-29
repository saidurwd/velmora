<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property string $axis
 * @property string|null $from_state
 * @property string $to_state
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 */
class OrderStatusEntry extends Model
{
    protected $table = 'order_status_history';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
