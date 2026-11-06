<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\SupportMessages;
use App\Modules\Engagement\Services\SupportUploads;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff answer a ticket, or leave a note for colleagues only. An answer marks the ticket "answered" (waiting for the customer), records the first-response time once, takes
 * the ticket for the person answering if nobody has it, and tells the customer. An internal note changes nothing the customer can see. A closed ticket must be opened first.
 * Who may (`support.reply`) is decided by the route.
 */
final class StaffReplyToSupportTicket
{
    public function __construct(private readonly SupportUploads $uploads, private readonly SupportMessages $messages, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws EngagementException not_found | ticket_closed | too_short | too_long | too_many_files | file_too_large | file_type | file_unsafe | file_unreadable
     */
    public function handle(Actor $actor, string $ticketPublicId, string $body, bool $internal, array $files = []): void
    {
        $body = trim(BanglaText::input($body));
        OpenSupportTicket::assertBody($body);
        $prepared = $this->uploads->prepare($files);

        $messageId = Transactions::run(function () use ($actor, $ticketPublicId, $body, $internal, $prepared): int {
            $ticket = DB::table('support_tickets')->where('public_id', $ticketPublicId)->lockForUpdate()->first() ?? throw new EngagementException('not_found');   // unique public_id
            if ($ticket->status === 'closed') {
                throw new EngagementException('ticket_closed');
            }
            $now = $this->clock->now();
            $messageId = DB::table('support_messages')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $ticket->id, 'author_type' => 'staff', 'author_id' => $actor->id, 'body' => $body, 'is_internal' => $internal, 'created_at' => $now]);
            if (! $internal) {
                DB::table('support_tickets')->where('id', $ticket->id)->update([
                    'status' => 'answered', 'first_responded_at' => $ticket->first_responded_at ?? $now, 'assignee_id' => $ticket->assignee_id ?? $actor->id, 'last_message_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->audit->record($internal ? 'support.staff_note' : 'support.staff_replied', 'support_ticket', ['status' => $ticket->status], ['status' => $internal ? $ticket->status : 'answered'], $actor, (int) $ticket->id);
            $this->uploads->store($prepared, $messageId);

            return $messageId;
        });

        if (! $internal) {
            $this->messages->replyReceived(DB::table('support_tickets')->where('public_id', $ticketPublicId)->first() ?? throw new EngagementException('not_found'), $messageId);   // after the answer is saved
        }
    }
}
