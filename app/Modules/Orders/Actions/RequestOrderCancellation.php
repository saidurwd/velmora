<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\CancellationRequestRules;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A customer asks the shop to cancel their own order. It only asks: nothing is cancelled and no money moves. One request at a time per order (the database refuses a second).
 * Someone else's order is "not found".
 */
final class RequestOrderCancellation
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws OrderException order_not_found | already_cancelled | order_complete | already_shipped | cancel_yourself | no_physical_line | cancel_request_exists */
    public function handle(int $userId, string $orderPublicId, ?string $note): string
    {
        $order = Order::query()->where('public_id', $orderPublicId)->where('user_id', $userId)->with('items')->first() ?? throw new OrderException('order_not_found');   // unique public_id
        if (($reason = CancellationRequestRules::deniedReason($order)) !== null) {
            throw new OrderException($reason);
        }

        $now = $this->clock->now();
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);
        $publicId = strtolower((string) Str::ulid());
        try {
            $id = DB::table('order_cancellation_requests')->insertGetId(['public_id' => $publicId, 'order_id' => $order->id, 'user_id' => $userId, 'status' => 'requested', 'open_key' => (string) $order->id, 'note' => $note, 'created_at' => $now, 'updated_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            throw new OrderException('cancel_request_exists');
        }
        $this->audit->record('order.cancellation_requested', 'order_cancellation_request', [], ['order' => $order->order_number], null, $id);

        return $publicId;
    }
}
