<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\RatingStats;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Staff decide a review. Approving makes it public and counts its stars; rejecting (or taking down a published one) needs a reason that the author is shown, and stops the
 * stars counting. The review is locked while it is decided, so two staff members cannot both win, and the star counts change in the same transaction. Who may
 * (`reviews.moderate`) is decided by the route.
 */
final class ModerateReview
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly RatingStats $stats) {}

    /** @throws EngagementException not_found | not_pending */
    public function approve(Actor $actor, string $publicId): void
    {
        Transactions::run(function () use ($actor, $publicId): void {
            $review = $this->lock($publicId);
            if ($review->status !== 'pending') {
                throw new EngagementException('not_pending');
            }
            $this->set($review, $actor, 'published', null);
            $this->stats->add((int) $review->product_id, (int) $review->rating);
        });
    }

    /** @throws EngagementException not_found | not_open | reason_required */
    public function reject(Actor $actor, string $publicId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new EngagementException('reason_required');
        }
        Transactions::run(function () use ($actor, $publicId, $reason): void {
            $review = $this->lock($publicId);
            if (! in_array($review->status, ['pending', 'published'], true)) {
                throw new EngagementException('not_open');
            }
            $this->set($review, $actor, 'rejected', $reason);
            if ($review->status === 'published') {
                $this->stats->remove((int) $review->product_id, (int) $review->rating);
            }
        });
    }

    private function lock(string $publicId): \stdClass
    {
        return DB::table('reviews')->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new EngagementException('not_found');   // unique public_id
    }

    private function set(\stdClass $review, Actor $actor, string $to, ?string $reason): void
    {
        $now = $this->clock->now();
        DB::table('reviews')->where('id', $review->id)->update(['status' => $to, 'moderated_by' => $actor->id, 'moderated_at' => $now, 'rejection_reason' => $reason, 'updated_at' => $now]);
        $this->audit->record('reviews.'.$to, 'review', ['status' => $review->status], ['status' => $to, 'reason' => $reason], $actor, (int) $review->id);
    }
}
