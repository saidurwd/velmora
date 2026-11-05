<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\DTOs\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** What the customer's own privacy page shows, and what staff see in the queue. Read-only. */
final class PrivacyReader
{
    public const STATUSES = ['requested', 'ready', 'failed', 'expired', 'approved', 'rejected', 'cancelled', 'blocked', 'done'];

    public function __construct(private readonly CustomerProfiles $profiles, private readonly PersonalDataCatalog $catalog) {}

    /**
     * One customer's own requests, newest first.
     *
     * @return array<int, \stdClass>
     */
    public function forCustomer(int $userId): array
    {
        return DB::table('privacy_requests')->where('user_id', $userId)->orderByDesc('id')->limit(20)   // index (user_id, type, requested_at)
            ->get(['public_id', 'type', 'status', 'requested_at', 'expires_at', 'decision_reason'])->all();
    }

    /** @return list<string> the codes ("orders.open") of what stands in the way of erasing this customer now */
    public function blockersFor(int $userId): array
    {
        $all = [];
        foreach ($this->catalog->erasers() as $eraser) {
            foreach ($eraser->blockers($userId) as $code) {
                $all[] = $eraser->section().'.'.$code;
            }
        }

        return $all;
    }

    /**
     * The staff queue, with the customers' names fetched in one query for the whole page.
     *
     * @param  array<string, string>  $filters  type | status
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function queue(array $filters): LengthAwarePaginator
    {
        $page = DB::table('privacy_requests')
            ->when(($filters['type'] ?? '') !== '', fn ($q) => $q->where('type', $filters['type']))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))   // index (type, status, requested_at)
            ->orderByDesc('id')->paginate(25);

        $names = $this->profiles->findManyByUserIds(array_values(array_unique(array_map(fn ($r) => (int) $r->user_id, $page->items()))));
        $page->through(function ($row) use ($names) {
            $row->customer = $names[(int) $row->user_id] ?? null;

            return $row;
        });

        return $page;
    }

    /** @return array{request: \stdClass, customer: CustomerProfile|null, blockers: list<string>}|null */
    public function detail(string $publicId): ?array
    {
        $row = DB::table('privacy_requests')->where('public_id', $publicId)->first();   // unique public_id
        if ($row === null) {
            return null;
        }

        return [
            'request' => $row, 'customer' => $this->profiles->findByUserId((int) $row->user_id),
            'blockers' => $row->type === 'erasure' && in_array($row->status, ['requested', 'blocked', 'approved'], true) ? $this->blockersFor((int) $row->user_id) : [],
        ];
    }
}
