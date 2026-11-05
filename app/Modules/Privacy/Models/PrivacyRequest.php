<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $type
 * @property string $status
 * @property string|null $reason
 * @property string|null $decision_reason
 * @property string|null $failure
 * @property string|null $file_key
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 */
class PrivacyRequest extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];   // written only by the Actions, never from a request

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
