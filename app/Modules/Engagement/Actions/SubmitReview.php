<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Orders\Contracts\PurchasedLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A customer reviews something they really bought. The proof of purchase comes from Orders (the customer's own order, in a state that allows it), never from the request.
 * One review per order line (the database decides, so a double submit makes one). It waits for staff before anyone sees it. The spam rules are checked here as well as on
 * the form: no links, no repeating the same text, not too short, not too long.
 */
final class SubmitReview
{
    public const BODY_MIN = 10;

    public const BODY_MAX = 2000;

    public const TITLE_MAX = 100;

    public function __construct(
        private readonly PurchasedLines $lines,
        private readonly PurchasableVariants $variants,
        private readonly CustomerProfiles $profiles,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws EngagementException not_found | not_eligible | too_old | duplicate | invalid | too_short | too_long | links_not_allowed | repeat_text */
    public function handle(int $userId, string $orderPublicId, string $variantPublicId, int $rating, ?string $title, string $body): string
    {
        $title = $title === null ? null : self::clean($title);
        $body = self::clean($body);
        $title = $title === '' ? null : $title;

        if ($rating < 1 || $rating > 5 || ($title !== null && mb_strlen($title) > self::TITLE_MAX)) {
            throw new EngagementException('invalid');
        }
        if (mb_strlen($body) < self::BODY_MIN) {
            throw new EngagementException('too_short', ['min' => self::BODY_MIN]);
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            throw new EngagementException('too_long', ['max' => self::BODY_MAX]);
        }
        if (self::hasLink($body) || ($title !== null && self::hasLink($title))) {
            throw new EngagementException('links_not_allowed');
        }

        $line = $this->lines->find($userId, $orderPublicId, $variantPublicId) ?? throw new EngagementException('not_found');
        if (! $line->reviewable) {
            throw new EngagementException('not_eligible');
        }
        $now = $this->clock->now();
        if ($line->placedAt->lessThan($now->subDays(max(1, (int) config('engagement.review_window_days', 180))))) {
            throw new EngagementException('too_old', ['days' => (int) config('engagement.review_window_days', 180)]);
        }
        if (DB::table('reviews')->where('order_item_id', $line->orderItemId)->exists()) {   // unique order_item_id
            throw new EngagementException('duplicate');
        }
        if (DB::table('reviews')->where('user_id', $userId)->where('body', $body)->where('created_at', '>', $now->subDays(30))->exists()) {   // index (user_id, id)
            throw new EngagementException('repeat_text');
        }

        $variant = $this->variants->details([$line->variantId], 'en')[$line->variantId] ?? null;
        if ($variant === null || $variant->productId < 1) {
            throw new EngagementException('not_found');
        }

        $publicId = strtolower((string) Str::ulid());
        try {
            $id = DB::table('reviews')->insertGetId([
                'public_id' => $publicId, 'product_id' => $variant->productId, 'variant_id' => $line->variantId, 'order_item_id' => $line->orderItemId, 'user_id' => $userId,
                'rating' => $rating, 'title' => $title, 'body' => $body, 'locale' => preg_match('/[\x{0980}-\x{09FF}]/u', $body.' '.$title) === 1 ? 'bn' : 'en',
                'author_name' => self::shortName($this->profiles->findByUserId($userId)?->name), 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new EngagementException('duplicate');   // two submissions at once: the unique key let one through
        }
        $this->audit->record('reviews.submitted', 'review', [], ['rating' => $rating], null, $id);

        return $publicId;
    }

    /** Text as typed, made safe to keep: normalised, control characters removed, trimmed. */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', BanglaText::input($text)));
    }

    public static function hasLink(string $text): bool
    {
        return preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|net|org|info|biz|xyz|io|co|bd|me|shop|store|link|click|top)\b)~i', $text) === 1;
    }

    /** "Amina Rahman" becomes "Amina R."; a single name stays as it is. */
    public static function shortName(?string $name): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return null;
        }
        $first = mb_substr($parts[0], 0, 40);

        return count($parts) > 1 ? $first.' '.mb_substr(end($parts), 0, 1).'.' : $first;
    }
}
