<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $shipment_id
 * @property string $provider_status
 * @property string $internal_status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property string $source
 * @property int|null $actor_id
 * @property string|null $note
 * @property array<string, scalar|null>|null $summary
 */
class ShipmentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'summary' => 'array'];
    }
}
