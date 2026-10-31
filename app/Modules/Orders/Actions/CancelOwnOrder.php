<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\FulfillmentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\BanglaText;

/**
 * A customer cancels their own order, only while nothing has been done with it: still pending (not yet confirmed), nothing shipped, and no money
 * taken or on its way. Anything else is for the shop to handle (refunds are always a person's decision). Someone else's order is "not found".
 */
final class CancelOwnOrder
{
    public function __construct(private readonly TransitionOrder $transition) {}

    /** Why this order cannot be cancelled by its owner right now, or null when it can. */
    public static function deniedReason(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Cancelled => 'already_cancelled',
            $order->payment_status === PaymentStatus::Pending => 'cancel_payment_in_progress',
            $order->status !== OrderStatus::Pending || $order->fulfillment_status !== FulfillmentStatus::Unfulfilled || ! in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Failed], true) => 'cancel_not_allowed',
            default => null,
        };
    }

    /** @throws OrderException */
    public function handle(Actor $customer, string $orderPublicId, ?string $note): void
    {
        $order = Order::query()->where('public_id', $orderPublicId)->where('user_id', $customer->id)->first() ?? throw new OrderException('order_not_found');
        if (($reason = self::deniedReason($order)) !== null) {
            throw new OrderException($reason);
        }

        $note = $note === null ? '' : mb_substr(trim(BanglaText::input($note)), 0, 150);
        $this->transition->status($customer, $order->id, OrderStatus::Cancelled, 'Cancelled by the customer'.($note === '' ? '' : ': '.$note));
    }
}
