<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Bulk;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Platform\Contracts\BulkAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** What the two review bulk actions share: the list, how a row is described, and how a failure is put into words. */
abstract class ReviewBulk implements BulkAction
{
    public function screen(): string
    {
        return 'reviews';
    }

    public function permissions(): array
    {
        return ['reviews.moderate'];
    }

    public function fields(Authenticatable $by): array
    {
        return [];
    }

    public function describe(array $ids): array
    {
        // unique public_id: the chosen rows
        return DB::table('reviews')->whereIn('public_id', $ids)->get(['public_id', 'rating', 'title', 'body', 'author_name'])->mapWithKeys(fn ($r): array => [
            (string) $r->public_id => __('engagement::bulk.review', ['author' => $r->author_name ?? __('engagement::reviews.anonymous'), 'stars' => (int) $r->rating, 'text' => mb_substr(trim((string) ($r->title ?: $r->body)), 0, 60)]),
        ])->all();
    }

    public function explain(Throwable $e): string
    {
        return $e instanceof EngagementException && \Lang::has('engagement::errors.'.$e->reason) ? __('engagement::errors.'.$e->reason) : __('platform::bulk.unknown');
    }
}
