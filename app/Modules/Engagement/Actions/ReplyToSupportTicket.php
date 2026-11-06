<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\SupportUploads;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The customer adds to their own ticket. A ticket the shop was waiting on stays open; one the shop answered goes back to "open"; one that was closed opens again only
 * within the reopen window (a setting), after that they start a new ticket. The ticket is locked while this happens. Someone else's ticket is "not found".
 */
final class ReplyToSupportTicket
{
    public function __construct(private readonly SupportUploads $uploads, private readonly Clock $clock, private readonly AuditLogger $audit, private readonly Settings $settings) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws EngagementException not_found | too_short | too_long | closed_for_good | too_many_files | file_too_large | file_type | file_unsafe | file_unreadable
     */
    public function handle(int $userId, string $ticketPublicId, string $body, array $files = []): void
    {
        $body = trim(BanglaText::input($body));
        OpenSupportTicket::assertBody($body);
        $prepared = $this->uploads->prepare($files);

        Transactions::run(function () use ($userId, $ticketPublicId, $body, $prepared): void {
            $ticket = DB::table('support_tickets')->where('public_id', $ticketPublicId)->where('user_id', $userId)->lockForUpdate()->first() ?? throw new EngagementException('not_found');   // unique public_id
            $now = $this->clock->now();
            $changes = ['last_message_at' => $now, 'updated_at' => $now];
            if ($ticket->status === 'closed') {
                $days = max(1, $this->settings->int('support.reopen_days', (int) config('engagement.support.reopen_days', 14)));
                if ($ticket->closed_at === null || $now->greaterThan(CarbonImmutable::parse((string) $ticket->closed_at, 'UTC')->addDays($days))) {
                    throw new EngagementException('closed_for_good', ['days' => $days]);
                }
                $changes += ['status' => 'open', 'closed_at' => null];
            } elseif ($ticket->status === 'answered') {
                $changes += ['status' => 'open'];
            }
            DB::table('support_tickets')->where('id', $ticket->id)->update($changes);
            $messageId = DB::table('support_messages')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $ticket->id, 'author_type' => 'customer', 'author_id' => $userId, 'body' => $body, 'is_internal' => false, 'created_at' => $now]);
            $this->audit->record('support.customer_replied', 'support_ticket', ['status' => $ticket->status], ['status' => $changes['status'] ?? $ticket->status], null, (int) $ticket->id);
            $this->uploads->store($prepared, $messageId);
        });
    }
}
