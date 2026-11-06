<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** The customer takes back their request while it is still waiting. One conditional update, so a decision made a moment earlier wins. Someone else's request is "not found". */
final class WithdrawCancellationRequest
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws OrderException order_not_found | cancel_request_decided */
    public function handle(int $userId, string $requestPublicId): void
    {
        $row = DB::table('order_cancellation_requests')->where('public_id', $requestPublicId)->where('user_id', $userId)->first() ?? throw new OrderException('order_not_found');   // unique public_id
        $now = $this->clock->now();
        if (DB::table('order_cancellation_requests')->where('id', $row->id)->where('status', 'requested')->update(['status' => 'withdrawn', 'open_key' => null, 'updated_at' => $now]) !== 1) {
            throw new OrderException('cancel_request_decided');
        }
        $this->audit->record('order.cancellation_withdrawn', 'order_cancellation_request', [], [], null, (int) $row->id);
    }
}
