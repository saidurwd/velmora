<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\BanglaText;

/** A note on an order: internal (staff only) or visible to the customer. Notes are never edited; add another. */
final class AddOrderNote
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(Actor $actor, Order $order, string $body, string $visibility): OrderNote
    {
        return OrderNote::query()->create([
            'order_id' => $order->id, 'author_id' => $actor->id, 'visibility' => $visibility === 'customer' ? 'customer' : 'internal',
            'body' => trim(BanglaText::input($body)), 'created_at' => $this->clock->now(),
        ]);
    }
}
