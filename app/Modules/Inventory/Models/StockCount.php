<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $location_id
 * @property CountStatus $status
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 */
class StockCount extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => CountStatus::class, 'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'count_id')->orderBy('id');
    }
}
