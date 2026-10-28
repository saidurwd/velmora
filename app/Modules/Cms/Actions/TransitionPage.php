<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class TransitionPage
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** Publishes now, or at $at (UTC) when given. A page needs a title and body in the default language. */
    public function publish(Actor $actor, Page $page, ?CarbonInterface $at = null): Page
    {
        $default = (string) config('app.default_locale');
        $ready = DB::table('cms_page_translations')->where('page_id', $page->id)->where('locale', $default)->where('title', '!=', '')->whereNotNull('body')->where('body', '!=', '')->exists();
        $ready || throw CmsException::notPublishable('content');

        if ($at !== null && $at->lessThanOrEqualTo($this->clock->now())) {
            throw CmsException::notPublishable('future');
        }

        $page->forceFill(['status' => 'published', 'publish_at' => $at, 'published_at' => $page->published_at ?? ($at ?? $this->clock->now())])->save();
        $this->audit->record('cms.page_published', $page, [], ['publish_at' => $at?->toIso8601String()], $actor);

        return $page;
    }

    public function unpublish(Actor $actor, Page $page): Page
    {
        $page->forceFill(['status' => 'draft', 'publish_at' => null])->save();
        $this->audit->record('cms.page_unpublished', $page, [], [], $actor);

        return $page;
    }
}
