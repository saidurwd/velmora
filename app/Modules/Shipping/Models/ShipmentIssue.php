<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $type
 * @property int|null $shipment_id
 * @property string $reference
 * @property array<string, mixed> $details
 * @property string $status
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution
 */
class ShipmentIssue extends Model
{
    use HasPublicId;

    protected $table = 'shipment_exceptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
