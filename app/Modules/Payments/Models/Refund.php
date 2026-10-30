<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\RefundMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $payment_id
 * @property int $order_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundStatus $status
 * @property RefundMethod $method
 * @property string $reason
 * @property string $refund_ref
 * @property string|null $provider_refund_id
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property string|null $proof_ref
 * @property string|null $failure_reason
 */
class Refund extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => RefundStatus::class, 'method' => RefundMethod::class];
    }
}
