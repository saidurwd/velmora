<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\ShipmentIssue;

/** Closes an item in the parcel exception queue: "resolved" (dealt with) or "ignored" (needs nothing). A note is required and the first to close it wins. It moves nothing else. */
final class ResolveShipmentIssue
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $issuePublicId, string $outcome, string $note): ShipmentIssue
    {
        if (! in_array($outcome, ['resolved', 'ignored'], true)) {
            throw new ShipmentException('issue_outcome_invalid');
        }
        $note = mb_substr(trim(BanglaText::input($note)), 0, 255);
        if ($note === '') {
            throw new ShipmentException('note_required');
        }

        return Transactions::run(function () use ($actor, $issuePublicId, $outcome, $note): ShipmentIssue {
            $now = $this->clock->now();
            $closed = ShipmentIssue::query()->where('public_id', $issuePublicId)->where('status', 'open')->update(['status' => $outcome, 'resolved_by' => $actor->id, 'resolved_at' => $now, 'resolution' => $note, 'updated_at' => $now]);
            $row = ShipmentIssue::query()->where('public_id', $issuePublicId)->first() ?? throw new ShipmentException('issue_not_found');
            if ($closed === 0) {
                throw new ShipmentException('issue_not_open');
            }
            $this->audit->record('shipment.issue_'.$outcome, $row, ['status' => 'open'], ['status' => $outcome, 'type' => $row->type, 'note' => $note], $actor);

            return $row;
        });
    }
}
