<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\RatingStats;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** The author takes their own review back. A published one stops counting in the same transaction. Someone else's review is "not found". */
final class WithdrawReview
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly RatingStats $stats) {}

    /** @throws EngagementException not_found | not_withdrawable */
    public function handle(int $userId, string $publicId): void
    {
        Transactions::run(function () use ($userId, $publicId): void {
            $review = DB::table('reviews')->where('public_id', $publicId)->where('user_id', $userId)->lockForUpdate()->first();   // unique public_id
            if ($review === null) {
                throw new EngagementException('not_found');
            }
            if (! in_array($review->status, ['pending', 'published'], true)) {
                throw new EngagementException('not_withdrawable');
            }
            DB::table('reviews')->where('id', $review->id)->update(['status' => 'withdrawn', 'updated_at' => $this->clock->now()]);
            if ($review->status === 'published') {
                $this->stats->remove((int) $review->product_id, (int) $review->rating);
            }
            $this->audit->record('reviews.withdrawn', 'review', ['status' => $review->status], ['status' => 'withdrawn'], null, (int) $review->id);
        });
    }
}
