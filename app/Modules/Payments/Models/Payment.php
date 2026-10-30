<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Platform\Concerns\HasPublicId;
use App\Modules\Platform\DTOs\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property string $method_code
 * @property string $provider
 * @property int $amount_minor
 * @property string $currency
 * @property int $captured_minor
 * @property int $refunded_minor
 * @property PaymentState $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 */
class Payment extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => PaymentState::class, 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    /** What could still be given back: received minus refunds already asked for or made is checked under a lock elsewhere; this is the simple view. */
    public function refundableMinor(): int
    {
        return max(0, $this->captured_minor - $this->refunded_minor);
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('attempt');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }
}
