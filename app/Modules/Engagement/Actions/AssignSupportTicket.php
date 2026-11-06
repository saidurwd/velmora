<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\IdentityAccess\Contracts\StaffDirectory;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Who looks after a ticket. Anyone who may reply can take a ticket for themselves; giving it to someone else, or letting go of someone else's, needs `support.manage`. The new person must be able to reply. */
final class AssignSupportTicket
{
    public function __construct(private readonly StaffDirectory $staff, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws EngagementException not_found | not_allowed | invalid_assignee */
    public function handle(Actor $actor, string $ticketPublicId, ?int $assigneeId, bool $canManage): void
    {
        $ticket = DB::table('support_tickets')->where('public_id', $ticketPublicId)->first() ?? throw new EngagementException('not_found');   // unique public_id
        $takingOwn = $assigneeId === $actor->id;
        $lettingGoOwn = $assigneeId === null && $ticket->assignee_id === $actor->id;
        if (! $canManage && ! $takingOwn && ! $lettingGoOwn) {
            throw new EngagementException('not_allowed');
        }
        if ($assigneeId !== null && ! array_key_exists($assigneeId, $this->staff->withPermission('support.reply'))) {
            throw new EngagementException('invalid_assignee');
        }

        DB::table('support_tickets')->where('id', $ticket->id)->update(['assignee_id' => $assigneeId, 'updated_at' => $this->clock->now()]);
        $this->audit->record('support.assigned', 'support_ticket', ['assignee' => $ticket->assignee_id], ['assignee' => $assigneeId], $actor, (int) $ticket->id);
    }
}
