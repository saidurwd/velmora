<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Privacy;

use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * A customer's returns: what they asked to send back and why go into their data copy. On erasure the words they typed are cleared (the return itself stays as a record of
 * what came back); a return that is still open (goods asked for, allowed or on their way) blocks erasure until it is finished.
 */
final class ReturnPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly CustomerOrders $orders) {}

    public function section(): string
    {
        return 'returns';
    }

    public function export(int $userId): array
    {
        $ids = $this->orders->orderIdsOwnedBy($userId);
        $rows = DB::table('return_requests')->whereIn('order_id', $ids)->where('kind', 'customer')->orderBy('id')->get(['id', 'status', 'reason_code', 'reason', 'decision_note', 'created_at']);   // index order_id
        $items = DB::table('return_items')->whereIn('return_id', $rows->pluck('id'))->get(['return_id', 'quantity'])->groupBy('return_id');

        return $rows->map(fn ($r) => ['asked_at' => $r->created_at, 'status' => $r->status, 'reason' => $r->reason_code, 'your_words' => $r->reason, 'our_answer' => $r->decision_note, 'items_asked' => (int) ($items->get($r->id)?->sum('quantity') ?? 0)])->all();
    }

    public function blockers(int $userId): array
    {
        return DB::table('return_requests')->whereIn('order_id', $this->orders->orderIdsOwnedBy($userId))->whereIn('status', ['requested', 'authorized', 'received'])->exists() ? ['open'] : [];
    }

    public function erase(int $userId): void
    {
        DB::table('return_requests')->whereIn('order_id', $this->orders->orderIdsOwnedBy($userId))->whereNotNull('reason')->update(['reason' => null]);
    }
}
