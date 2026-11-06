<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderMessages;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Staff answer a customer's request to cancel. APPROVING records the shop's agreement and tells the customer; it does not cancel the order and moves no money (a person
 * refunds and cancels on the normal screens). REFUSING needs a reason the customer is told. The first answer wins (one conditional update). Who may (`orders.cancel`) is decided by the route.
 */
final class DecideCancellationRequest
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly OrderMessages $messages) {}

    /** @throws OrderException order_not_found | cancel_request_decided */
    public function approve(Actor $actor, string $requestPublicId, ?string $note): void
    {
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);
        $this->decide($actor, $requestPublicId, 'approved', $note, false);
    }

    /** @throws OrderException order_not_found | cancel_request_decided | reason_required */
    public function reject(Actor $actor, string $requestPublicId, string $reason): void
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new OrderException('reason_required');
        }
        $this->decide($actor, $requestPublicId, 'rejected', $reason, true);
    }

    private function decide(Actor $actor, string $publicId, string $to, ?string $note, bool $closes): void
    {
        $row = Transactions::run(function () use ($actor, $publicId, $to, $note, $closes): \stdClass {
            $row = DB::table('order_cancellation_requests')->where('public_id', $publicId)->first() ?? throw new OrderException('order_not_found');   // unique public_id
            $now = $this->clock->now();
            $changes = ['status' => $to, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_note' => $note, 'updated_at' => $now] + ($closes ? ['open_key' => null] : []);
            if (DB::table('order_cancellation_requests')->where('id', $row->id)->where('status', 'requested')->update($changes) !== 1) {
                throw new OrderException('cancel_request_decided');
            }
            $this->audit->record('order.cancellation_'.$to, 'order_cancellation_request', ['status' => 'requested'], ['status' => $to, 'note' => $note], $actor, (int) $row->id);

            return $row;
        });

        $order = Order::query()->whereKey($row->order_id)->first();
        if ($order === null) {
            return;
        }
        foreach (array_filter(['sms', $order->contact_email !== null ? 'email' : null]) as $channel) {
            $this->messages->tryToSend($order, "order.cancel_request_{$to}.{$channel}", ['note' => (string) $note, 'link' => route('account.orders.show', ['locale' => $order->locale, 'order' => $order->public_id])], "cancel-request-{$to}|{$publicId}");
        }
    }
}
