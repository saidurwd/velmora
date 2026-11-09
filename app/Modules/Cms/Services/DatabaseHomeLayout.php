<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Contracts\HomeLayout;
use App\Modules\Cms\DTOs\HomeSectionView;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\HtmlSanitizer;
use Illuminate\Support\Facades\DB;

final class DatabaseHomeLayout implements HomeLayout
{
    public function __construct(private readonly Clock $clock, private readonly HtmlSanitizer $html) {}

    public function sections(string $locale): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $rows = DB::table('cms_home_sections')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $default = (string) config('app.default_locale');
        $texts = DB::table('cms_home_section_translations')->whereIn('section_id', $rows->pluck('id')->all())->whereIn('locale', [$locale, $default])->get()->groupBy('section_id');

        $out = [];
        foreach ($rows as $row) {
            $mine = $texts->get($row->id, collect());
            $own = $mine->firstWhere('locale', $locale);
            $fallback = $mine->firstWhere('locale', $default);
            $title = trim((string) (($own->title ?? '') ?: ($fallback->title ?? '')));
            $body = $row->type === 'text' ? trim((string) (($own->body ?? '') ?: ($fallback->body ?? ''))) : '';

            if ($row->type === 'text' && $body === '') {
                continue; // a text block with no text in any language shows nothing
            }
            $out[] = new HomeSectionView((string) $row->type, $title === '' ? null : $title, $body === '' ? null : $this->html->clean($body), $row->collection_ref === null ? null : (string) $row->collection_ref); // cleaned again on the way out
        }

        return $out;
    }
}
