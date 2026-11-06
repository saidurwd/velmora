<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** The customer closes their own ticket ("sorted, thank you"). One conditional update; someone else's ticket is "not found". */
final class CloseOwnSupportTicket
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws EngagementException not_found | already_closed */
    public function handle(int $userId, string $ticketPublicId): void
    {
        $ticket = DB::table('support_tickets')->where('public_id', $ticketPublicId)->where('user_id', $userId)->first() ?? throw new EngagementException('not_found');   // unique public_id
        $now = $this->clock->now();
        if (DB::table('support_tickets')->where('id', $ticket->id)->whereIn('status', ['open', 'answered'])->update(['status' => 'closed', 'closed_at' => $now, 'updated_at' => $now]) !== 1) {
            throw new EngagementException('already_closed');
        }
        $this->audit->record('support.customer_closed', 'support_ticket', ['status' => $ticket->status], ['status' => 'closed'], null, (int) $ticket->id);
    }
}
