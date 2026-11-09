<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Catalog\Contracts\HomeCollections;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\HtmlSanitizer;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A block of the home page. A collection rail must name an active collection; a text block needs text in the default language (sanitised, at most 5,000
 * characters each); the window is Dhaka wall-clock time and must end after it starts. A new section starts hidden: only someone who may publish can show or hide one.
 */
final class SaveHomeSection
{
    public const TYPES = ['banner_hero', 'banner_strip', 'categories', 'collection', 'new_arrivals', 'popular', 'text'];

    public const MAX_SECTIONS = 20;

    public const BODY_MAX = 5000;

    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly StorefrontCache $cache, private readonly HtmlSanitizer $html, private readonly HomeCollections $collections) {}

    /**
     * @param  array<string, array{title?: string|null, body?: string|null}>  $translations
     * @return string the section's public id
     *
     * @throws CmsException
     */
    public function handle(Actor $actor, ?string $publicId, string $type, ?string $collectionRef, ?bool $active, bool $canShow, ?string $startsLocal, ?string $endsLocal, array $translations): string
    {
        in_array($type, self::TYPES, true) || throw CmsException::homeInvalid();
        $default = (string) config('app.default_locale');

        $ref = null;
        if ($type === 'collection') {
            ($collectionRef !== null && $collectionRef !== '' && $this->collections->exists($collectionRef)) || throw CmsException::homeCollectionMissing();
            $ref = $collectionRef;
        }

        $zone = (string) config('app.display_timezone');
        $starts = ($startsLocal ?? '') === '' ? null : CarbonImmutable::parse((string) $startsLocal, $zone)->utc();
        $ends = ($endsLocal ?? '') === '' ? null : CarbonImmutable::parse((string) $endsLocal, $zone)->utc();
        ($starts === null || $ends === null || $ends->greaterThan($starts)) || throw CmsException::badWindow();

        $clean = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $title = mb_substr(trim(BanglaText::input((string) ($translations[$locale]['title'] ?? ''))), 0, 160);
            $raw = $type === 'text' ? trim((string) ($translations[$locale]['body'] ?? '')) : '';
            mb_strlen($raw) <= self::BODY_MAX || throw CmsException::homeTooLong((string) $locale);
            $body = $raw === '' ? '' : trim($this->html->clean($raw));
            if ($title !== '' || $body !== '') {
                $clean[(string) $locale] = [$title === '' ? null : $title, $body === '' ? null : $body];
            }
        }
        $type !== 'text' || ($clean[$default][1] ?? null) !== null || throw CmsException::homeBodyRequired($default);

        $id = Transactions::run(function () use ($actor, $publicId, $type, $ref, $active, $canShow, $starts, $ends, $clean): int {
            DB::table('cms_home_sections')->lockForUpdate()->pluck('id');             // one editor at a time: the table is small
            $existing = $publicId === null ? null : (DB::table('cms_home_sections')->where('public_id', $publicId)->first() ?? throw CmsException::homeNotFound());
            ($existing !== null || DB::table('cms_home_sections')->count() < self::MAX_SECTIONS) || throw CmsException::homeFull();

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $fields = ['collection_ref' => $ref, 'starts_at' => $starts?->format('Y-m-d H:i:s.u'), 'ends_at' => $ends?->format('Y-m-d H:i:s.u'), 'updated_at' => $now];
            if ($existing === null) {
                $id = DB::table('cms_home_sections')->insertGetId($fields + [
                    'public_id' => strtolower((string) Str::ulid()), 'type' => $type, 'sort_order' => ((int) DB::table('cms_home_sections')->max('sort_order')) + 1,
                    'is_active' => $canShow && $active === true, 'created_by' => $actor->id, 'created_at' => $now,
                ]);
            } else {
                $id = (int) $existing->id;
                $canShow && $active !== null && $fields['is_active'] = $active;
                $type === $existing->type || throw CmsException::homeInvalid();           // a section keeps its type
                DB::table('cms_home_sections')->where('id', $id)->update($fields);
            }

            DB::table('cms_home_section_translations')->where('section_id', $id)->whereNotIn('locale', array_keys($clean))->delete();
            foreach ($clean as $locale => [$title, $body]) {
                DB::table('cms_home_section_translations')->updateOrInsert(['section_id' => $id, 'locale' => $locale], ['title' => $title, 'body' => $body]);
            }
            $this->audit->record($existing === null ? 'cms.home_section_created' : 'cms.home_section_updated', 'home_section', [], ['type' => $type, 'languages' => array_keys($clean)], $actor, $id);

            return $id;
        });
        $this->cache->bump();

        return (string) DB::table('cms_home_sections')->where('id', $id)->value('public_id');
    }
}
