<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Staff close a ticket, or open a closed one again. One conditional update, so two people pressing the button cannot both win. Who may (`support.manage`) is decided by the route. */
final class ChangeSupportTicketStatus
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws EngagementException not_found | already_closed */
    public function close(Actor $actor, string $ticketPublicId): void
    {
        $ticket = $this->find($ticketPublicId);
        $now = $this->clock->now();
        if (DB::table('support_tickets')->where('id', $ticket->id)->whereIn('status', ['open', 'answered'])->update(['status' => 'closed', 'closed_at' => $now, 'updated_at' => $now]) !== 1) {
            throw new EngagementException('already_closed');
        }
        $this->audit->record('support.closed', 'support_ticket', ['status' => $ticket->status], ['status' => 'closed'], $actor, (int) $ticket->id);
    }

    /** @throws EngagementException not_found | not_closed */
    public function reopen(Actor $actor, string $ticketPublicId): void
    {
        $ticket = $this->find($ticketPublicId);
        if (DB::table('support_tickets')->where('id', $ticket->id)->where('status', 'closed')->update(['status' => 'open', 'closed_at' => null, 'updated_at' => $this->clock->now()]) !== 1) {
            throw new EngagementException('not_closed');
        }
        $this->audit->record('support.reopened', 'support_ticket', ['status' => 'closed'], ['status' => 'open'], $actor, (int) $ticket->id);
    }

    private function find(string $publicId): \stdClass
    {
        return DB::table('support_tickets')->where('public_id', $publicId)->first() ?? throw new EngagementException('not_found');   // unique public_id
    }
}
