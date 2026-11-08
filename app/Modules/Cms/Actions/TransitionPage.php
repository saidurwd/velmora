<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\SlugRedirects;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a version live, now or later, and takes it down. Publishing an older version is a rollback: newer
 * versions stay in the history. Every change takes the page's row lock, so two workers cannot interleave.
 */
final class TransitionPage
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock, private readonly StorefrontCache $cache, private readonly SlugRedirects $redirects) {}

    /** Publishes version $number now. Doing it twice for the same version changes nothing. */
    public function publish(Actor $actor, Page $page, int $number): Page
    {
        $locked = Transactions::run(function () use ($actor, $page, $number): Page {
            $locked = $this->lock($page);
            $version = $this->version($locked, $number);
            $this->assertReady($version);

            if ($locked->status === 'published' && $locked->live_version_id === $version->id) {
                $locked->scheduled_version_id === $version->id && $this->clearSchedule($locked);

                return $locked;
            }

            $this->makeLive($locked, $version);
            $locked->scheduled_version_id === $version->id && $this->clearSchedule($locked);
            $this->audit->record('cms.page_published', $locked, [], ['version' => $version->number], $actor);

            return $locked;
        });
        $this->cache->bump();

        return $locked;
    }

    /** Version $number goes live at $at (UTC) by itself. One schedule per page; a new one replaces the old. */
    public function schedule(Actor $actor, Page $page, int $number, CarbonInterface $at): Page
    {
        $at->greaterThan($this->clock->now()) || throw CmsException::notPublishable('future');

        return Transactions::run(function () use ($actor, $page, $number, $at): Page {
            $locked = $this->lock($page);
            $version = $this->version($locked, $number);
            $this->assertReady($version);

            $locked->forceFill(['scheduled_version_id' => $version->id, 'publish_at' => $at])->save();
            $this->audit->record('cms.page_scheduled', $locked, [], ['version' => $version->number, 'publish_at' => $at->toIso8601String()], $actor);

            return $locked;
        });
    }

    public function cancelSchedule(Actor $actor, Page $page): Page
    {
        return Transactions::run(function () use ($actor, $page): Page {
            $locked = $this->lock($page);
            if ($locked->scheduled_version_id !== null) {
                $this->clearSchedule($locked);
                $this->audit->record('cms.page_schedule_cancelled', $locked, [], [], $actor);
            }

            return $locked;
        });
    }

    public function unpublish(Actor $actor, Page $page): Page
    {
        $locked = Transactions::run(function () use ($actor, $page): Page {
            $locked = $this->lock($page);
            $locked->forceFill(['status' => 'draft', 'live_version_id' => null])->save();
            $this->audit->record('cms.page_unpublished', $locked, [], [], $actor);

            return $locked;
        });
        $this->cache->bump();

        return $locked;
    }

    /**
     * The scheduler's step for one page. Returns true when it published. Not due, already done by another
     * worker, or no longer publishable all return false; the last also drops the schedule and writes an audit line.
     */
    public function publishDue(Actor $system, int $pageId): bool
    {
        $published = Transactions::run(function () use ($system, $pageId): bool {
            $locked = Page::query()->whereKey($pageId)->lockForUpdate()->first();
            if ($locked === null || $locked->scheduled_version_id === null || $locked->publish_at === null || $locked->publish_at->greaterThan($this->clock->now())) {
                return false; // someone else got there first, or it was cancelled or moved
            }

            $version = PageVersion::query()->with('translations')->findOrFail($locked->scheduled_version_id);

            try {
                DB::transaction(function () use ($locked, $version): void { // a savepoint: a failed go-live leaves nothing half done
                    $this->assertReady($version);
                    $this->makeLive($locked, $version);
                });
            } catch (CmsException $e) {
                $this->clearSchedule($locked);
                $this->audit->record('cms.page_schedule_failed', $locked, [], ['version' => $version->number, 'reason' => $e->reason], $system);

                return false;
            }

            $this->clearSchedule($locked);
            $this->audit->record('cms.page_published', $locked, [], ['version' => $version->number, 'scheduled' => true], $system);

            return true;
        });
        $published && $this->cache->bump();

        return $published;
    }

    private function lock(Page $page): Page
    {
        return Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
    }

    private function version(Page $page, int $number): PageVersion
    {
        return PageVersion::query()->with('translations')->where('page_id', $page->id)->where('number', $number)->first() ?? throw CmsException::versionNotFound();
    }

    private function clearSchedule(Page $page): void
    {
        $page->forceFill(['scheduled_version_id' => null, 'publish_at' => null])->save();
    }

    /** A page needs a title and body in the default language. */
    private function assertReady(PageVersion $version): void
    {
        $row = $version->translations->firstWhere('locale', (string) config('app.default_locale'));
        ($row !== null && trim($row->title) !== '' && trim((string) $row->body) !== '') || throw CmsException::notPublishable('content');
    }

    /** Copies the version into what the public reads and points the page at it. A slug another page already owns stops it. */
    private function makeLive(Page $page, PageVersion $version): void
    {
        $previous = DB::table('cms_page_translations')->where('page_id', $page->id)->pluck('slug', 'locale')->all();
        $locales = $version->translations->pluck('locale')->all();
        DB::table('cms_page_translations')->where('page_id', $page->id)->whereNotIn('locale', $locales)->delete();
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        foreach ($version->translations as $t) {
            try {
                DB::table('cms_page_translations')->updateOrInsert(['page_id' => $page->id, 'locale' => $t->locale], [
                    'title' => $t->title, 'slug' => $t->slug, 'body' => $t->body, 'seo_title' => $t->seo_title, 'seo_description' => $t->seo_description,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CmsException::slugTaken($t->locale, $t->slug);
            }

            $this->redirects->forget('page', $t->locale, $t->slug);
            if (isset($previous[$t->locale]) && $previous[$t->locale] !== $t->slug) {
                $this->redirects->record('page', $page->id, $t->locale, (string) $previous[$t->locale]);
            }
        }

        $page->forceFill(['status' => 'published', 'live_version_id' => $version->id, 'published_at' => $page->published_at ?? $this->clock->now()])->save();
    }
}
