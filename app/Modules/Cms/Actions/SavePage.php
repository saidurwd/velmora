<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\HtmlSanitizer;
use App\Modules\Platform\Support\Slugger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Static pages (about, delivery, policies): bilingual, sanitised HTML, slug unique per language.
 * Every save is a NEW immutable version and becomes the draft. What the public sees changes only when a
 * version is published (TransitionPage). Two people saving from the same starting version cannot both win.
 */
final class SavePage
{
    public function __construct(private readonly HtmlSanitizer $html, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  array<string, array{title?: string|null, slug?: string|null, body?: string|null, seo_title?: string|null, seo_description?: string|null}>  $translations
     * @param  int|null  $baseVersion  the draft version number the editor started from (0 when the page has none); required for an existing page
     */
    public function handle(Actor $actor, ?Page $page, array $translations, ?int $baseVersion = null): Page
    {
        $default = (string) config('app.default_locale');
        $supported = (array) config('app.supported_locales');

        $translations = array_filter($translations, fn (array $t) => trim((string) ($t['title'] ?? '')) !== '');
        foreach (array_keys($translations) as $locale) {
            in_array($locale, $supported, true) || throw CmsException::unsupportedLanguage((string) $locale);
        }
        isset($translations[$default]) || throw CmsException::defaultLanguageRequired($default);

        return Transactions::run(function () use ($actor, $page, $translations, $baseVersion): Page {
            if ($page === null) {
                $page = new Page(['created_by' => $actor->id]);
                $page->save();
                $isNew = true;
            } else {
                $isNew = false;
                $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail(); // one saver at a time per page
                $current = $locked->draft_version_id === null ? 0 : (int) DB::table('cms_page_versions')->where('id', $locked->draft_version_id)->value('number');
                $current === $baseVersion || throw CmsException::editConflict();
                $page = $locked;
            }

            $number = ((int) DB::table('cms_page_versions')->where('page_id', $page->id)->max('number')) + 1;
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $versionId = DB::table('cms_page_versions')->insertGetId(['page_id' => $page->id, 'number' => $number, 'created_by' => $actor->id, 'created_at' => $now]);

            $rows = [];
            foreach ($translations as $locale => $t) {
                $title = BanglaText::input((string) ($t['title'] ?? ''));
                $rows[] = [
                    'version_id' => $versionId, 'locale' => (string) $locale, 'title' => $title,
                    'slug' => $this->uniqueSlug((string) $locale, Slugger::make(trim((string) ($t['slug'] ?? '')) !== '' ? (string) ($t['slug'] ?? '') : $title), $page->id),
                    'body' => ($t['body'] ?? '') === '' ? null : $this->html->clean((string) $t['body']),
                    'seo_title' => ($t['seo_title'] ?? '') === '' ? null : BanglaText::input((string) $t['seo_title']),
                    'seo_description' => ($t['seo_description'] ?? '') === '' ? null : BanglaText::input((string) $t['seo_description']),
                    'created_at' => $now,
                ];
            }
            DB::table('cms_page_version_translations')->insert($rows);

            $page->forceFill(['draft_version_id' => $versionId])->save();
            $page->unsetRelation('draftVersion');
            $this->audit->record($isNew ? 'cms.page_created' : 'cms.page_updated', $page, [], ['version' => $number, 'locales' => array_keys($translations)], $actor);

            return $page;
        });
    }

    /** A slug is taken when another page uses it live, or has it in its current draft. */
    private function uniqueSlug(string $locale, string $base, int $ownerId): string
    {
        $base = $base !== '' ? $base : 'page';
        $candidate = $base;

        for ($i = 2; $this->taken($locale, $candidate, $ownerId); $i++) {
            $suffix = '-'.$i;
            $candidate = mb_substr($base, 0, Slugger::MAX_LENGTH - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    private function taken(string $locale, string $slug, int $ownerId): bool
    {
        return DB::table('cms_page_translations')->where('locale', $locale)->where('slug', $slug)->where('page_id', '!=', $ownerId)->exists()
            || DB::table('cms_page_version_translations as vt')->join('cms_pages as p', 'p.draft_version_id', '=', 'vt.version_id')
                ->where('vt.locale', $locale)->where('vt.slug', $slug)->where('p.id', '!=', $ownerId)->exists();
    }
}
