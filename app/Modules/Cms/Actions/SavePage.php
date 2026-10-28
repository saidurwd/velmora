<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageTranslation;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\HtmlSanitizer;
use App\Modules\Platform\Support\Slugger;
use App\Modules\Platform\Support\SlugRedirects;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Static pages (about, delivery, FAQ, policies): bilingual, sanitised HTML, slug unique per language. */
final class SavePage
{
    public function __construct(private readonly HtmlSanitizer $html, private readonly AuditLogger $audit, private readonly SlugRedirects $redirects, private readonly StorefrontCache $cache) {}

    /** @param  array<string, array{title?: string|null, slug?: string|null, body?: string|null, seo_title?: string|null, seo_description?: string|null}>  $translations */
    public function handle(Actor $actor, ?Page $page, array $translations): Page
    {
        $default = (string) config('app.default_locale');
        $supported = (array) config('app.supported_locales');

        $translations = array_filter($translations, fn (array $t) => trim((string) ($t['title'] ?? '')) !== '');
        foreach (array_keys($translations) as $locale) {
            in_array($locale, $supported, true) || throw CmsException::unsupportedLanguage((string) $locale);
        }
        isset($translations[$default]) || throw CmsException::defaultLanguageRequired($default);

        return Transactions::run(function () use ($actor, $page, $translations): Page {
            $isNew = $page === null;
            $page ??= new Page(['created_by' => $actor->id]);
            $page->save();

            PageTranslation::query()->where('page_id', $page->id)->whereNotIn('locale', array_keys($translations))->delete();

            foreach ($translations as $locale => $t) {
                $title = BanglaText::input((string) ($t['title'] ?? ''));
                $slug = $this->uniqueSlug((string) $locale, Slugger::make(trim((string) ($t['slug'] ?? '')) !== '' ? (string) ($t['slug'] ?? '') : $title), $page->id);
                $previous = PageTranslation::query()->where('page_id', $page->id)->where('locale', $locale)->value('slug');
                PageTranslation::query()->updateOrCreate(['page_id' => $page->id, 'locale' => $locale], [
                    'title' => $title,
                    'slug' => $slug,
                    'body' => ($t['body'] ?? '') === '' ? null : $this->html->clean((string) $t['body']),
                    'seo_title' => ($t['seo_title'] ?? '') === '' ? null : BanglaText::input((string) $t['seo_title']),
                    'seo_description' => ($t['seo_description'] ?? '') === '' ? null : BanglaText::input((string) $t['seo_description']),
                ]);
                $this->redirects->forget('page', (string) $locale, $slug);
                if ($previous !== null && $previous !== $slug) {
                    $this->redirects->record('page', $page->id, (string) $locale, (string) $previous);
                }
            }

            $page->unsetRelation('translations');
            $this->audit->record($isNew ? 'cms.page_created' : 'cms.page_updated', $page, [], ['locales' => array_keys($translations)], $actor);

            $this->cache->bump();

            return $page;
        });
    }

    private function uniqueSlug(string $locale, string $base, int $ownerId): string
    {
        $base = $base !== '' ? $base : 'page';
        $candidate = $base;

        for ($i = 2; DB::table('cms_page_translations')->where('locale', $locale)->where('slug', $candidate)->where('page_id', '!=', $ownerId)->exists(); $i++) {
            $suffix = '-'.$i;
            $candidate = mb_substr($base, 0, Slugger::MAX_LENGTH - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }
}
