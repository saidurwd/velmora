<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** A person has looked at a flagged order and says it is fine. The flags stay on record; the reason is kept as an internal note. */
final class ClearOrderReview
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws OrderException */
    public function handle(Actor $actor, int $orderId, string $reason): Order
    {
        $reason = trim(BanglaText::input($reason));
        if ($reason === '') {
            throw new OrderException('reason_required');
        }

        $order = Transactions::run(function () use ($actor, $orderId, $reason): Order {
            /** @var Order $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! $order->needs_review) {
                throw new OrderException('not_under_review');
            }

            DB::table('orders')->where('id', $orderId)->update(['needs_review' => false, 'updated_at' => $this->clock->now()]);
            DB::table('order_notes')->insert(['order_id' => $orderId, 'author_id' => $actor->id, 'visibility' => 'internal', 'body' => __('orders::orders.review_cleared_note', ['reason' => $reason]), 'created_at' => $this->clock->now()]);

            return $order->refresh();
        });

        $this->audit->record('order.review.cleared', $order, ['needs_review' => true], ['needs_review' => false, 'reason' => $reason], $actor);

        return $order;
    }
}
