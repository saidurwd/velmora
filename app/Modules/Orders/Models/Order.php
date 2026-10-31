<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Platform\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonInterface $created_at
 * @property string $public_id
 * @property string|null $placement_key
 * @property string $order_number
 * @property int|null $user_id
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $contact_email
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property FulfillmentStatus $fulfillment_status
 * @property bool $needs_review
 * @property OrderChannel $channel
 * @property string $locale
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $shipping_minor
 * @property int $cod_fee_minor
 * @property int $tax_minor
 * @property int $grand_total_minor
 * @property bool $tax_inclusive
 * @property string $payment_method_code
 * @property string|null $shipping_method_code
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $cancel_reason
 */
class Order extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class, 'payment_status' => PaymentStatus::class, 'fulfillment_status' => FulfillmentStatus::class, 'channel' => OrderChannel::class,
            'needs_review' => 'boolean', 'tax_inclusive' => 'boolean', 'placed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusEntry, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusEntry::class)->orderBy('id');
    }

    /** @return HasMany<OrderNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderBy('id');
    }

    /** @return HasMany<OrderFraudFlag, $this> */
    public function flags(): HasMany
    {
        return $this->hasMany(OrderFraudFlag::class)->orderBy('id');
    }

    /** @return HasMany<OrderTaxLine, $this> */
    public function taxLines(): HasMany
    {
        return $this->hasMany(OrderTaxLine::class)->orderBy('id');
    }
}
