<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $payment_id
 * @property string $provider
 * @property string $merchant_ref
 * @property int $attempt
 * @property string|null $provider_session
 * @property string|null $provider_validation_id
 * @property string|null $provider_txn_id
 * @property TransactionStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, scalar|null>|null $request_summary
 * @property CarbonImmutable|null $verified_at
 * @property string|null $failure_code
 */
class PaymentTransaction extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TransactionStatus::class, 'request_summary' => 'array', 'verified_at' => 'immutable_datetime'];
    }
}
