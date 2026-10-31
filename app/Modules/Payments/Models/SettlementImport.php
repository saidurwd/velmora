<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $provider
 * @property string $file_hash
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property int $row_count
 * @property int $matched_count
 * @property int $problem_count
 * @property int|null $imported_by
 */
class SettlementImport extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_from' => 'immutable_datetime', 'period_to' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
