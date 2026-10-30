<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $type
 * @property int|null $payment_id
 * @property int|null $order_id
 * @property string|null $reference
 * @property array<string, mixed> $details
 * @property string $status
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution
 */
class ReconciliationException extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'immutable_datetime'];
    }
}
