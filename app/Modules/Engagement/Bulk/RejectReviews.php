<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Bulk;

use App\Modules\Engagement\Actions\ModerateReview;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Contracts\Auth\Authenticatable;

/** Rejects the chosen reviews with ONE reason that every author is shown, each through the same Action as the queue's own "reject" button (which insists on the reason). */
final class RejectReviews extends ReviewBulk
{
    public function __construct(private readonly ModerateReview $moderate) {}

    public function code(): string
    {
        return 'reviews.reject';
    }

    public function label(): string
    {
        return 'engagement::bulk.reject';
    }

    public function fields(Authenticatable $by): array
    {
        return [['name' => 'reason', 'type' => 'text', 'label' => 'engagement::reviews.reason_label', 'required' => true, 'max' => 255]];
    }

    public function run(Actor $actor, Authenticatable $by, string $id, array $params): void
    {
        $this->moderate->reject($actor, $id, $params['reason'] ?? '');
    }
}
