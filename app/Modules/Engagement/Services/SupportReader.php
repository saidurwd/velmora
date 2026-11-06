<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\DTOs\CustomerProfile;
use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** What the support pages show. The customer's pages are always for the one customer asked about and never include an internal note; names and order numbers are fetched for a whole page at once. */
final class SupportReader
{
    public function __construct(private readonly OrderDirectory $orders, private readonly CustomerOrders $owned, private readonly CustomerProfiles $profiles, private readonly StaffDirectory $staff, private readonly Clock $clock) {}

    /** @return list<array{public_id: string, number: string}> the customer's most recent orders, for "which order is it about?" */
    public function orderChoices(int $userId): array
    {
        $ids = array_slice($this->owned->orderIdsOwnedBy($userId), 0, 20);

        return array_values(array_map(fn (array $o): array => ['public_id' => $o['public_id'], 'number' => $o['number']], $this->orders->references($ids)));
    }

    /** @return array<int, \stdClass> the customer's own tickets, most recently active first */
    public function mine(int $userId): array
    {
        return DB::table('support_tickets')->where('user_id', $userId)->orderByDesc('last_message_at')->limit(100)->get(['public_id', 'reference', 'subject', 'category', 'status', 'last_message_at', 'created_at'])->all();   // index (user_id, last_message_at)
    }

    /** @return array{ticket: \stdClass, order: array{number: string, public_id: string}|null, messages: array<int, array<string, mixed>>}|null null when the ticket is not this customer's */
    public function thread(int $userId, string $ticketPublicId): ?array
    {
        $ticket = DB::table('support_tickets')->where('public_id', $ticketPublicId)->where('user_id', $userId)->first();   // unique public_id
        if ($ticket === null) {
            return null;
        }

        return ['ticket' => $ticket, 'order' => $ticket->order_id === null ? null : ($this->orders->references([(int) $ticket->order_id])[(int) $ticket->order_id] ?? null), 'messages' => $this->messages((int) $ticket->id, false)];
    }

    /**
     * @param  array<string, string>  $filters  status | assignee (mine|none) | category | overdue | q
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function queue(array $filters, int $staffId): LengthAwarePaginator
    {
        $now = $this->clock->now();
        $query = DB::table('support_tickets')
            ->when(($filters['status'] ?? 'active') === 'active', fn ($q) => $q->whereIn('status', ['open', 'answered']), fn ($q) => $q->where('status', $filters['status']))   // index (status, sla_due_at)
            ->when(($filters['assignee'] ?? '') === 'mine', fn ($q) => $q->where('assignee_id', $staffId))
            ->when(($filters['assignee'] ?? '') === 'none', fn ($q) => $q->whereNull('assignee_id'))
            ->when(($filters['category'] ?? '') !== '', fn ($q) => $q->where('category', $filters['category']))
            ->when(($filters['overdue'] ?? '') === '1', fn ($q) => $q->where('status', 'open')->whereNull('first_responded_at')->where('sla_due_at', '<', $now))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where('reference', strtoupper(trim($filters['q']))))
            ->orderBy('sla_due_at');
        $page = $query->paginate(25);

        $items = $page->items();
        $customers = $this->profiles->findManyByUserIds(array_values(array_unique(array_map(fn ($t): int => (int) $t->user_id, $items))));
        $orders = $this->orders->references(array_values(array_unique(array_filter(array_map(fn ($t): int => (int) $t->order_id, $items)))));
        $staff = $this->staff->names(array_values(array_map(fn ($t) => $t->assignee_id === null ? null : (int) $t->assignee_id, $items)));
        $page->through(function ($t) use ($customers, $orders, $staff, $now) {
            $t->customer = isset($customers[(int) $t->user_id]) ? $customers[(int) $t->user_id]->name : null;
            $t->order_number = $t->order_id === null ? null : ($orders[(int) $t->order_id]['number'] ?? null);
            $t->assignee = $t->assignee_id === null ? null : ($staff[(int) $t->assignee_id] ?? null);
            $t->overdue = $t->status === 'open' && $t->first_responded_at === null && (string) $t->sla_due_at < $now->format('Y-m-d H:i:s.u');

            return $t;
        });

        return $page;
    }

    /** @return array{ticket: \stdClass, customer: CustomerProfile|null, order: array{number: string, public_id: string}|null, assignee: string|null, assignable: array<int, string>, messages: array<int, array<string, mixed>>}|null */
    public function staffThread(string $ticketPublicId): ?array
    {
        $ticket = DB::table('support_tickets')->where('public_id', $ticketPublicId)->first();   // unique public_id
        if ($ticket === null) {
            return null;
        }

        return [
            'ticket' => $ticket, 'customer' => $this->profiles->findByUserId((int) $ticket->user_id),
            'order' => $ticket->order_id === null ? null : ($this->orders->references([(int) $ticket->order_id])[(int) $ticket->order_id] ?? null),
            'assignee' => $this->staff->names([$ticket->assignee_id === null ? null : (int) $ticket->assignee_id])[(int) $ticket->assignee_id] ?? null,
            'assignable' => $this->staff->withPermission('support.reply'), 'messages' => $this->messages((int) $ticket->id, true),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function messages(int $ticketId, bool $forStaff): array
    {
        $rows = DB::table('support_messages')->where('ticket_id', $ticketId)->when(! $forStaff, fn ($q) => $q->where('is_internal', false))->orderBy('id')->get();   // index (ticket_id, id)
        $files = DB::table('support_attachments')->whereIn('message_id', $rows->pluck('id'))->orderBy('id')->get(['public_id', 'message_id', 'original_name', 'size_bytes'])->groupBy('message_id');
        $names = $this->staff->names(array_values($rows->where('author_type', 'staff')->pluck('author_id')->map(fn ($v): int => (int) $v)->unique()->all()));

        return array_values($rows->map(fn ($m) => [
            'id' => (string) $m->public_id, 'mine' => $m->author_type === 'customer', 'who' => $m->author_type === 'customer' ? '' : ($forStaff ? ($names[(int) $m->author_id] ?? '') : ''), 'body' => (string) $m->body, 'at' => (string) $m->created_at,
            'internal' => (bool) $m->is_internal, 'files' => $files->get($m->id, collect())->map(fn ($f) => ['id' => (string) $f->public_id, 'name' => (string) $f->original_name, 'size' => (int) $f->size_bytes])->values()->all(),
        ])->all());
    }
}
