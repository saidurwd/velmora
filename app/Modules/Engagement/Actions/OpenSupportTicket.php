<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\SupportUploads;
use App\Modules\Engagement\Services\TicketReferences;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A signed-in customer opens a conversation with the shop, about an order of their own if they wish. Their words are kept as text; the order is checked to be theirs;
 * a handful of tickets can be open at once. The answer is due within the first-response time (a setting). Guests do not have tickets.
 */
final class OpenSupportTicket
{
    public const CATEGORIES = ['order', 'delivery', 'payment', 'return', 'account', 'other'];

    public function __construct(
        private readonly OrderDirectory $orders,
        private readonly SupportUploads $uploads,
        private readonly TicketReferences $references,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return string the new ticket's public id
     *
     * @throws EngagementException invalid | too_short | too_long | order_not_found | too_many_open | too_many_files | file_too_large | file_type | file_unsafe | file_unreadable
     */
    public function handle(int $userId, string $locale, string $subject, string $category, ?string $orderPublicId, string $body, array $files = []): string
    {
        $subject = mb_substr(trim(BanglaText::input($subject)), 0, 150);
        $body = trim(BanglaText::input($body));
        if (mb_strlen($subject) < 3 || ! in_array($category, self::CATEGORIES, true)) {
            throw new EngagementException('invalid');
        }
        self::assertBody($body);

        $orderId = null;
        if ($orderPublicId !== null && $orderPublicId !== '') {
            $order = $this->orders->findByPublicId($orderPublicId);
            if ($order === null || $order['user_id'] !== $userId) {
                throw new EngagementException('order_not_found');   // never reveal that someone else's order exists
            }
            $orderId = $order['id'];
        }
        $prepared = $this->uploads->prepare($files);

        $open = (int) config('engagement.support.max_open_tickets', 5);
        if (DB::table('support_tickets')->where('user_id', $userId)->whereIn('status', ['open', 'answered'])->count() >= $open) {   // index (user_id, last_message_at)
            throw new EngagementException('too_many_open', ['max' => $open]);
        }

        $now = $this->clock->now();
        $hours = max(1, $this->settings->int('support.first_response_hours', (int) config('engagement.support.first_response_hours', 24)));
        $publicId = strtolower((string) Str::ulid());

        return Transactions::run(function () use ($userId, $locale, $subject, $category, $orderId, $body, $prepared, $now, $hours, $publicId): string {
            $ticketId = DB::table('support_tickets')->insertGetId([
                'public_id' => $publicId, 'reference' => $this->references->make(), 'user_id' => $userId, 'order_id' => $orderId, 'subject' => $subject, 'category' => $category, 'status' => 'open',
                'locale' => in_array($locale, ['en', 'bn'], true) ? $locale : 'en', 'sla_due_at' => $now->addHours($hours), 'last_message_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $messageId = DB::table('support_messages')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $ticketId, 'author_type' => 'customer', 'author_id' => $userId, 'body' => $body, 'is_internal' => false, 'created_at' => $now]);
            $this->audit->record('support.ticket_opened', 'support_ticket', [], ['category' => $category], null, $ticketId);
            $this->uploads->store($prepared, $messageId);   // last: if a file cannot be kept, the whole ticket is undone

            return $publicId;
        });
    }

    /** @throws EngagementException too_short | too_long */
    public static function assertBody(string $body): void
    {
        if (mb_strlen($body) < 5) {
            throw new EngagementException('too_short', ['min' => 5]);
        }
        $max = (int) config('engagement.support.body_max', 5000);
        if (mb_strlen($body) > $max) {
            throw new EngagementException('too_long', ['max' => $max]);
        }
    }
}
