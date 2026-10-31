<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;

/**
 * Adds a guest order to a customer's account. The caller must already have proved the person owns the order (the signed confirmation link
 * that only the shopper received): this Action does not, and it never takes an order from one account to another. The database repeats
 * the rule: an owner can be set once.
 */
final class LinkOrderToCustomer
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws OrderException */
    public function handle(Actor $actor, string $orderPublicId, int $userId): void
    {
        Transactions::run(function () use ($actor, $orderPublicId, $userId): void {
            $linked = Order::query()->where('public_id', $orderPublicId)->whereNull('user_id')->update(['user_id' => $userId, 'updated_at' => $this->clock->now()]);
            if ($linked === 1) {
                $order = Order::query()->where('public_id', $orderPublicId)->firstOrFail();
                $this->audit->record('order.linked_to_account', $order, [], ['user_id' => $userId], $actor);

                return;
            }

            $owner = Order::query()->where('public_id', $orderPublicId)->value('user_id') ?? throw new OrderException('order_not_found');
            if ((int) $owner !== $userId) {
                throw new OrderException('order_already_linked'); // belongs to someone else
            }
        });
    }
}
