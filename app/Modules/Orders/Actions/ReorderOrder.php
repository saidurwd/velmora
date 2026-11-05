<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Cart\Contracts\CartFiller;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * "Buy again": puts the lines of one of the customer's own past orders in their cart, at today's price and subject to the cart's own rules (stock, limits, whether it is still
 * sold). A line that cannot go in is reported with the reason and the rest still go in; where only part of a quantity is available, that part goes in. Nothing is bought
 * and no stock is held: the customer reviews the cart as usual. Another customer's order is "not found".
 */
final class ReorderOrder
{
    public function __construct(private readonly CartFiller $cart) {}

    /**
     * @return list<array{name: string, requested: int, added: int, status: string, reason: string|null, params: array<string, string|int>}>
     *
     * @throws ModelNotFoundException when the order is not this customer's
     */
    public function handle(int $userId, string $publicId, string $locale): array
    {
        $order = Order::query()->where('user_id', $userId)->where('public_id', $publicId)->with('items')->firstOrFail();   // unique public_id
        $owner = CartOwner::user($userId);

        $lines = [];
        foreach ($order->items as $item) {
            $result = $this->cart->add($owner, (int) $item->variant_id, (int) $item->quantity, $locale);
            $lines[] = ['name' => $item->name($locale), 'requested' => $result->requested, 'added' => $result->added, 'status' => $result->status, 'reason' => $result->reason, 'params' => $result->params];
        }

        return $lines;
    }
}
