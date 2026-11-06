<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use Illuminate\Support\Facades\DB;

/** Who may open a file sent with a ticket: the customer whose ticket it is (never a file on an internal note), or staff who may look at support. Anyone else gets "not found". */
final class SupportAttachmentAccess
{
    /** @return array{key: string, mime: string, name: string}|null */
    public function find(string $attachmentPublicId, ?int $customerId, bool $staff): ?array
    {
        $row = DB::table('support_attachments as a')->join('support_messages as m', 'm.id', '=', 'a.message_id')->join('support_tickets as t', 't.id', '=', 'm.ticket_id')
            ->where('a.public_id', $attachmentPublicId)->first(['a.storage_key', 'a.mime', 'a.original_name', 'm.is_internal', 't.user_id']);   // unique public_id
        if ($row === null) {
            return null;
        }
        $allowed = $staff || ($customerId !== null && (int) $row->user_id === $customerId && ! $row->is_internal);

        return $allowed ? ['key' => (string) $row->storage_key, 'mime' => (string) $row->mime, 'name' => (string) $row->original_name] : null;
    }
}
