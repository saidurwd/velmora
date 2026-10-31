<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\ReconciliationException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;

/**
 * Closes a reconciliation exception: "resolved" (it was dealt with) or "ignored" (it needs nothing). A note is required, the first person to close
 * it wins (one conditional UPDATE), and it never moves money: money moves only through refunds, collections and verification. Who may close
 * (permission `payments.reconcile`) is decided by the caller.
 */
final class ResolveReconciliationException
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $exceptionPublicId, string $outcome, string $note): ReconciliationException
    {
        if (! in_array($outcome, ['resolved', 'ignored'], true)) {
            throw new PaymentException('exception_outcome_invalid');
        }
        $note = mb_substr(trim(BanglaText::input($note)), 0, 255);
        if ($note === '') {
            throw new PaymentException('exception_note_required');
        }

        return Transactions::run(function () use ($actor, $exceptionPublicId, $outcome, $note): ReconciliationException {
            $now = $this->clock->now();
            $closed = ReconciliationException::query()->where('public_id', $exceptionPublicId)->where('status', 'open')
                ->update(['status' => $outcome, 'resolved_by' => $actor->id, 'resolved_at' => $now, 'resolution' => $note, 'updated_at' => $now]);
            $row = ReconciliationException::query()->where('public_id', $exceptionPublicId)->first() ?? throw new PaymentException('exception_not_found');
            if ($closed === 0) {
                throw new PaymentException('exception_not_open'); // someone closed it a moment ago
            }
            $this->audit->record('reconciliation.'.$outcome, $row, ['status' => 'open'], ['status' => $outcome, 'type' => $row->type, 'note' => $note], $actor);

            return $row;
        });
    }
}
