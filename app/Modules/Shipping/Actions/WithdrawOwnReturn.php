<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;

/**
 * A customer takes back their own return before the goods are received (still waiting for a decision, or allowed but not yet sent). One conditional update, so a
 * decision made a moment earlier wins. Another customer's return is "not found". It changes no stock and no money.
 */
final class WithdrawOwnReturn
{
    public function __construct(private readonly CustomerOrders $orders, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws ShipmentException return_not_found | return_not_open */
    public function handle(Actor $customer, string $returnPublicId): ReturnRequest
    {
        return Transactions::run(function () use ($customer, $returnPublicId): ReturnRequest {
            $return = ReturnRequest::query()->where('public_id', $returnPublicId)->first();   // unique public_id
            if ($return === null || $customer->id === null || ! $this->orders->owns($customer->id, $return->order_id)) {
                throw new ShipmentException('return_not_found');
            }
            $now = $this->clock->now();
            $moved = DB::table('return_requests')->where('id', $return->id)->whereIn('status', ['requested', 'authorized'])
                ->update(['status' => 'cancelled', 'decision_note' => 'Withdrawn by the customer', 'decided_at' => $now, 'updated_at' => $now]);
            $fresh = ReturnRequest::query()->findOrFail($return->id);
            if ($moved !== 1) {
                throw new ShipmentException('return_not_open', ['status' => $fresh->status->value]);
            }
            $this->audit->record('return.withdrawn', $fresh, ['status' => $return->status->value], ['status' => 'cancelled'], $customer);

            return $fresh;
        });
    }
}
