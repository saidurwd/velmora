<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $courier
 * @property string $statement_ref
 * @property int $amount_minor
 * @property int $expected_minor
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable $received_at
 * @property int|null $recorded_by
 */
class CodRemittance extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
