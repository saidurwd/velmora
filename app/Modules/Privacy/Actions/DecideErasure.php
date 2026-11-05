<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Jobs\RunErasureJob;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use App\Modules\Privacy\Services\PrivacyMessages;
use Illuminate\Support\Facades\DB;

/**
 * Staff decide an erasure request. Approving is refused while anything is still open (an order on its way, an upcoming booking); rejecting needs a reason the customer is
 * told. The request is locked while it is decided, so two staff members deciding at once cannot both win. Who may decide (`privacy.manage`) is decided by the route.
 */
final class DecideErasure
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly PersonalDataCatalog $catalog, private readonly PrivacyMessages $messages) {}

    /** @throws PrivacyException not_found | not_waiting | blocked */
    public function approve(Actor $actor, string $publicId, ?string $note): void
    {
        $id = Transactions::run(function () use ($actor, $publicId, $note): int {
            $row = $this->lock($publicId);
            $blockers = $this->blockers((int) $row->user_id);
            if ($blockers !== []) {
                throw new PrivacyException('blocked', $blockers);   // the request stays as it was; staff see what to wait for
            }
            $now = $this->clock->now();
            DB::table('privacy_requests')->where('id', $row->id)->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_reason' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255), 'failure' => null, 'updated_at' => $now]);
            $this->audit->record('privacy.erasure_approved', 'privacy_request', ['status' => $row->status], ['status' => 'approved'], $actor, (int) $row->id);

            return (int) $row->id;
        });
        DB::afterCommit(fn () => RunErasureJob::dispatch($id));
    }

    /** @throws PrivacyException not_found | not_waiting | reason_required */
    public function reject(Actor $actor, string $publicId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new PrivacyException('reason_required');
        }
        $row = Transactions::run(function () use ($actor, $publicId, $reason): \stdClass {
            $row = $this->lock($publicId);
            $now = $this->clock->now();
            DB::table('privacy_requests')->where('id', $row->id)->update(['status' => 'rejected', 'open_key' => null, 'decided_by' => $actor->id, 'decided_at' => $now, 'completed_at' => $now, 'decision_reason' => $reason, 'updated_at' => $now]);
            $this->audit->record('privacy.erasure_rejected', 'privacy_request', ['status' => $row->status], ['status' => 'rejected'], $actor, (int) $row->id);

            return $row;
        });
        $this->messages->tell('erasure_rejected', $this->messages->contactOf((int) $row->user_id), (string) $row->locale, ['reason' => $reason, 'link' => route('account.privacy', ['locale' => $row->locale])], 'privacy.erasure_rejected|'.$row->public_id, (int) $row->user_id);
    }

    private function lock(string $publicId): \stdClass
    {
        $row = DB::table('privacy_requests')->where('public_id', $publicId)->where('type', 'erasure')->lockForUpdate()->first();   // unique public_id
        if ($row === null) {
            throw new PrivacyException('not_found');
        }
        if (! in_array($row->status, ['requested', 'blocked'], true)) {
            throw new PrivacyException('not_waiting');
        }

        return $row;
    }

    /** @return list<string> */
    private function blockers(int $userId): array
    {
        $all = [];
        foreach ($this->catalog->erasers() as $eraser) {
            foreach ($eraser->blockers($userId) as $code) {
                $all[] = $eraser->section().'.'.$code;
            }
        }

        return $all;
    }
}
