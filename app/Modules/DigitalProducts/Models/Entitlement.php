<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use App\Modules\DigitalProducts\Enums\EntitlementStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $variant_id
 * @property EntitlementStatus $status
 * @property int|null $download_limit
 * @property int $downloads_used
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $first_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 */
class Entitlement extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => EntitlementStatus::class, 'expires_at' => 'immutable_datetime', 'granted_at' => 'immutable_datetime', 'first_used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
