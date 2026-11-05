<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use App\Modules\Privacy\Services\PrivacyMessages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Carries out an APPROVED erasure: every module removes its part (each in its own transaction, each safe to repeat), the account goes last, the customer's data copies
 * are deleted, and the customer is told on the contact taken just before. If something new is open by now (an order placed after the approval) the request becomes
 * "blocked" and nothing is erased. If a part fails the request stays approved with the reason; running it again continues where it stopped.
 */
final class RunErasure
{
    public function __construct(private readonly PersonalDataCatalog $catalog, private readonly Clock $clock, private readonly AuditLogger $audit, private readonly PrivacyMessages $messages) {}

    public function handle(int $requestId): void
    {
        $request = DB::table('privacy_requests')->where('id', $requestId)->where('type', 'erasure')->first();
        if ($request === null || $request->status !== 'approved') {
            return;   // done already, or not approved
        }
        $userId = (int) $request->user_id;

        $blockers = [];
        foreach ($this->catalog->erasers() as $eraser) {
            foreach ($eraser->blockers($userId) as $code) {
                $blockers[] = $eraser->section().'.'.$code;
            }
        }
        if ($blockers !== []) {
            $now = $this->clock->now();
            DB::table('privacy_requests')->where('id', $requestId)->where('status', 'approved')->update(['status' => 'blocked', 'failure' => mb_substr(implode(',', $blockers), 0, 255), 'updated_at' => $now]);
            $this->audit->record('privacy.erasure_blocked', 'privacy_request', [], ['blockers' => $blockers], null, $requestId);

            return;
        }

        $contact = $this->messages->contactOf($userId);   // before it is erased
        try {
            foreach ($this->catalog->erasers() as $eraser) {
                Transactions::run(fn () => $eraser->erase($userId));
            }
        } catch (Throwable $e) {
            DB::table('privacy_requests')->where('id', $requestId)->update(['failure' => mb_substr($e::class, 0, 200), 'updated_at' => $this->clock->now()]);
            $this->audit->record('privacy.erasure_failed', 'privacy_request', [], ['error' => $e::class], null, $requestId);

            throw $e;   // the queue tries again; the erasers are safe to repeat
        }

        $this->deleteDataCopies($userId);
        $now = $this->clock->now();
        DB::table('privacy_requests')->where('id', $requestId)->where('status', 'approved')->update(['status' => 'done', 'open_key' => null, 'failure' => null, 'completed_at' => $now, 'updated_at' => $now]);
        DB::table('privacy_requests')->where('user_id', $userId)->whereNotNull('reason')->update(['reason' => null]);   // the customer's own words go too; index (user_id, type, requested_at)
        $this->audit->record('privacy.erased', 'privacy_request', [], [], null, $requestId);
        $this->messages->tell('erasure_done', $contact, (string) $request->locale, [], 'privacy.erasure_done|'.$request->public_id);   // no user id: the account is gone
    }

    /** The customer's data copies are deleted with them, whether or not they were downloaded. */
    private function deleteDataCopies(int $userId): void
    {
        $now = $this->clock->now();
        foreach (DB::table('privacy_requests')->where('user_id', $userId)->where('type', 'export')->whereNotNull('file_key')->get(['id', 'file_key']) as $copy) {
            Storage::disk('private')->delete((string) $copy->file_key);
            DB::table('privacy_requests')->where('id', $copy->id)->update(['file_key' => null, 'status' => 'expired', 'updated_at' => $now]);   // ready -> expired
        }
    }
}
