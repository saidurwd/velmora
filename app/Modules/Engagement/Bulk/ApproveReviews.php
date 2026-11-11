<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Bulk;

use App\Modules\Engagement\Actions\ModerateReview;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Contracts\Auth\Authenticatable;

/** Approves the chosen reviews, each through the same Action as the queue's own "approve" button. */
final class ApproveReviews extends ReviewBulk
{
    public function __construct(private readonly ModerateReview $moderate) {}

    public function code(): string
    {
        return 'reviews.approve';
    }

    public function label(): string
    {
        return 'engagement::bulk.approve';
    }

    public function run(Actor $actor, Authenticatable $by, string $id, array $params): void
    {
        $this->moderate->approve($actor, $id);
    }
}
