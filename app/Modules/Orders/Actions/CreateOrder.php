<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Writes an order exactly as priced: the order, its lines and addresses as snapshots, tax lines, and the first history entry on
 * each of the three axes. All or nothing. It does not price, reserve stock or take payment; the caller (checkout) does, in the
 * same transaction. The breakdown must add up, and the database re-checks that with the `orders` CHECK.
 */
final class CreateOrder
{
    public function __construct(private readonly OrderNumbers $numbers, private readonly Clock $clock) {}

    /** @throws OrderException */
    public function handle(NewOrder $new, OrderStatus $status = OrderStatus::Pending): Order
    {
        $b = $new->breakdown;

        if (! $b->reconciles()) {
            throw new OrderException('breakdown_mismatch');
        }
        if ($b->lines === [] || count($b->lines) !== count($new->lines)) {
            throw new OrderException('lines_mismatch');
        }
        foreach ($b->lines as $i => $priced) {
            if ($new->lines[$i]->variantId !== $priced->variantId) {
                throw new OrderException('lines_mismatch');
            }
        }
        if (array_filter($new->addresses, fn ($a) => $a->type === 'shipping') === [] && array_filter($new->lines, fn ($l) => $l->productType === 'physical') !== []) {
            throw new OrderException('address_required');
        }

        return Transactions::run(function () use ($new, $status): Order {
            $b = $new->breakdown;
            $now = $this->clock->now();

            $order = Order::query()->create([
                'order_number' => $this->numbers->next($now),
                'user_id' => $new->userId,
                'contact_name' => $new->contactName, 'contact_phone' => $new->contactPhone, 'contact_email' => $new->contactEmail,
                'status' => $status, 'payment_status' => PaymentStatus::Unpaid, 'fulfillment_status' => FulfillmentStatus::Unfulfilled,
                'needs_review' => $new->needsReview, 'channel' => $new->channel, 'locale' => $new->locale, 'currency' => $b->currency,
                'subtotal_minor' => $b->subtotalMinor, 'discount_minor' => $b->discountMinor(), 'shipping_minor' => $b->shippingMinor, 'cod_fee_minor' => $b->codFeeMinor,
                'tax_minor' => $b->taxMinor, 'grand_total_minor' => $b->grandTotalMinor, 'tax_inclusive' => $b->taxInclusive,
                'payment_method_code' => $new->paymentMethodCode, 'shipping_method_code' => $new->shippingMethodCode,
                'placed_at' => $now,
            ]);

            foreach ($b->lines as $i => $priced) {
                $line = $new->lines[$i];
                $order->items()->create([
                    'variant_id' => $line->variantId, 'product_type' => $line->productType, 'name_snapshot' => $line->name, 'sku_snapshot' => $line->sku, 'options_snapshot' => $line->options,
                    'quantity' => $priced->quantity, 'unit_price_minor' => $priced->unitPriceMinor, 'discount_minor' => $priced->lineDiscountMinor + $priced->orderDiscountShareMinor,
                    'tax_rate_bp' => $priced->taxRateBp, 'tax_minor' => $priced->taxMinor, 'line_total_minor' => $priced->grossMinor - $priced->lineDiscountMinor - $priced->orderDiscountShareMinor,
                ]);
            }

            foreach ($new->addresses as $a) {
                $order->addresses()->create([
                    'type' => $a->type, 'recipient_name' => $a->recipientName, 'phone' => $a->phone,
                    'division_id' => $a->divisionId, 'district_id' => $a->districtId, 'upazila_id' => $a->upazilaId, 'location_snapshot' => $a->location,
                    'postal_code' => $a->postalCode, 'area' => $a->area, 'address_line' => $a->addressLine, 'landmark' => $a->landmark,
                ]);
            }

            foreach ($b->extraTaxLines as $t) {
                $order->taxLines()->create(['kind' => $t['kind'], 'rate_bp' => $t['rate_bp'], 'taxable_minor' => $t['taxable_minor'], 'tax_minor' => $t['tax_minor'], 'created_at' => $now]);
            }

            foreach ([['status', $status->value], ['payment', PaymentStatus::Unpaid->value], ['fulfillment', FulfillmentStatus::Unfulfilled->value]] as [$axis, $state]) {
                DB::table('order_status_history')->insert([
                    'order_id' => $order->id, 'axis' => $axis, 'from_state' => null, 'to_state' => $state,
                    'actor_type' => $new->actor->type->value, 'actor_id' => $new->actor->id, 'reason' => 'placed', 'created_at' => $now,
                ]);
            }

            return $order;
        });
    }
}
