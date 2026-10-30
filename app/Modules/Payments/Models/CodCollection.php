<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int|null $shipment_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $source
 * @property CarbonImmutable $collected_at
 * @property int|null $recorded_by
 * @property string|null $note
 */
class CodCollection extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['collected_at' => 'immutable_datetime'];
    }
}
