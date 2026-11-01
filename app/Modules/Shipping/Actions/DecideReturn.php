<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Enums\ReturnStatus;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;

/** A person allows or refuses a return, or the return is withdrawn. The first decision wins (one conditional UPDATE). Who may decide (`shipping.manage`) is decided by the caller. */
final class DecideReturn
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws ShipmentException */
    public function authorize(Actor $actor, string $returnPublicId, string $shippingCostPayer = 'none'): ReturnRequest
    {
        if (! in_array($shippingCostPayer, ['shop', 'customer', 'none'], true)) {
            throw new ShipmentException('payer_invalid');
        }

        return $this->move($actor, $returnPublicId, ReturnStatus::Authorized, null, ['shipping_cost_payer' => $shippingCostPayer], [ReturnStatus::Requested]);
    }

    /** @throws ShipmentException */
    public function reject(Actor $actor, string $returnPublicId, string $reason): ReturnRequest
    {
        return $this->move($actor, $returnPublicId, ReturnStatus::Rejected, $this->reason($reason), [], [ReturnStatus::Requested]);
    }

    /** @throws ShipmentException */
    public function cancel(Actor $actor, string $returnPublicId, string $reason): ReturnRequest
    {
        return $this->move($actor, $returnPublicId, ReturnStatus::Cancelled, $this->reason($reason), [], [ReturnStatus::Requested, ReturnStatus::Authorized]);
    }

    private function reason(string $reason): string
    {
        $reason = mb_substr(trim(BanglaText::input($reason)), 0, 255);
        if ($reason === '') {
            throw new ShipmentException('reason_required');
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<ReturnStatus>  $from
     */
    private function move(Actor $actor, string $publicId, ReturnStatus $to, ?string $note, array $extra, array $from): ReturnRequest
    {
        $return = Transactions::run(function () use ($actor, $publicId, $to, $note, $extra, $from): ReturnRequest {
            $now = $this->clock->now();
            $before = (string) DB::table('return_requests')->where('public_id', $publicId)->value('status');
            $moved = DB::table('return_requests')->where('public_id', $publicId)->whereIn('status', array_map(fn (ReturnStatus $s) => $s->value, $from))
                ->update(['status' => $to->value, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_note' => $note, 'updated_at' => $now] + $extra);
            $row = ReturnRequest::query()->where('public_id', $publicId)->first() ?? throw new ShipmentException('return_not_found');
            if ($moved !== 1) {
                throw new ShipmentException('return_not_open', ['status' => $row->status->value]); // someone decided a moment ago
            }
            $this->audit->record('return.'.$to->value, $row, ['status' => $before], ['status' => $to->value, 'note' => $note] + $extra, $actor);

            return $row;
        });

        return $return;
    }
}
