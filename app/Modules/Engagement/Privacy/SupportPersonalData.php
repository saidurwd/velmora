<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Privacy;

use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A customer's support conversations: what they and the shop said (never the notes staff kept for each other) go into their data copy. A ticket still open or answered blocks
 * erasure until it is closed. On erasure the tickets, their messages and the files sent with them (also from the private disk) are deleted.
 */
final class SupportPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function section(): string
    {
        return 'support';
    }

    public function export(int $userId): array
    {
        $tickets = DB::table('support_tickets')->where('user_id', $userId)->orderBy('id')->get(['id', 'reference', 'subject', 'category', 'status', 'created_at']);   // index (user_id, last_message_at)
        $messages = DB::table('support_messages')->whereIn('ticket_id', $tickets->pluck('id'))->where('is_internal', false)->orderBy('id')->get(['id', 'ticket_id', 'author_type', 'body', 'created_at'])->groupBy('ticket_id');
        $files = DB::table('support_attachments')->whereIn('message_id', $messages->flatten()->pluck('id'))->get(['message_id', 'original_name'])->groupBy('message_id');

        return $tickets->map(fn ($t) => [
            'reference' => $t->reference, 'subject' => $t->subject, 'category' => $t->category, 'status' => $t->status, 'opened_at' => $t->created_at,
            'messages' => ($messages->get($t->id) ?? collect())->map(fn ($m) => ['from' => $m->author_type === 'customer' ? 'you' : 'the shop', 'at' => $m->created_at, 'text' => $m->body, 'files' => ($files->get($m->id) ?? collect())->pluck('original_name')->all()])->values()->all(),
        ])->all();
    }

    public function blockers(int $userId): array
    {
        return DB::table('support_tickets')->where('user_id', $userId)->whereIn('status', ['open', 'answered'])->exists() ? ['open'] : [];
    }

    public function erase(int $userId): void
    {
        $ticketIds = DB::table('support_tickets')->where('user_id', $userId)->pluck('id');
        if ($ticketIds->isEmpty()) {
            return;
        }
        $messageIds = DB::table('support_messages')->whereIn('ticket_id', $ticketIds)->pluck('id');
        $keys = DB::table('support_attachments')->whereIn('message_id', $messageIds)->pluck('storage_key')->all();
        DB::table('support_attachments')->whereIn('message_id', $messageIds)->delete();
        DB::table('support_messages')->whereIn('ticket_id', $ticketIds)->delete();
        DB::table('support_tickets')->where('user_id', $userId)->delete();
        if ($keys !== []) {
            Storage::disk((string) config('engagement.support.disk', 'private'))->delete($keys);   // the files go once the rows are gone
        }
    }
}
