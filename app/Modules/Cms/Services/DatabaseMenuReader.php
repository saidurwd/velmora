<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Contracts\MenuReader;
use App\Modules\Cms\DTOs\MenuLink;
use App\Modules\Cms\Support\SafeLink;
use Illuminate\Support\Facades\DB;

final class DatabaseMenuReader implements MenuReader
{
    public function items(string $menu, string $locale): array
    {
        $rows = DB::table('cms_menu_items')->where('menu', $menu)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $default = (string) config('app.default_locale');
        $texts = DB::table('cms_menu_item_translations')->whereIn('item_id', $rows->pluck('id')->all())->whereIn('locale', [$locale, $default])->get()->groupBy('item_id');
        $slugs = DB::table('cms_pages as p')->join('cms_page_translations as t', 't.page_id', '=', 'p.id')
            ->whereIn('p.id', $rows->whereNotNull('page_id')->pluck('page_id')->all())->where('p.status', 'published')->whereNull('p.deleted_at')->whereIn('t.locale', [$locale, $default])
            ->get(['p.id as page_id', 't.locale', 't.slug'])->groupBy('page_id');

        $link = function (\stdClass $row) use ($texts, $slugs, $locale, $default): ?MenuLink {
            $mine = $texts->get($row->id, collect());
            $label = trim((string) (($mine->firstWhere('locale', $locale)->label ?? '') ?: ($mine->firstWhere('locale', $default)->label ?? '')));
            if ($label === '') {
                return null;
            }
            if ($row->kind === 'group') {
                return new MenuLink($label, null);
            }
            if ($row->kind === 'page') {
                $pageSlugs = $slugs->get($row->page_id, collect());
                $own = $pageSlugs->firstWhere('locale', $locale);
                $slug = ($own ?? $pageSlugs->firstWhere('locale', $default))?->slug;

                return $slug === null ? null : new MenuLink($label, url('/'.($own !== null ? $locale : $default).'/pages/'.rawurlencode((string) $slug)));
            }

            $url = trim((string) (($mine->firstWhere('locale', $locale)->url ?? '') ?: ($mine->firstWhere('locale', $default)->url ?? '')));

            return SafeLink::isSafe($url) ? new MenuLink($label, str_starts_with($url, '/') ? url($url) : $url) : null; // checked again here: never render what was not safe
        };

        $children = [];
        foreach ($rows->whereNotNull('parent_id') as $row) {
            ($made = $link($row)) === null || $children[$row->parent_id][] = $made;
        }

        $out = [];
        foreach ($rows->whereNull('parent_id') as $row) {
            $made = $link($row);
            if ($made === null) {
                continue;
            }
            if ($row->kind === 'group') {
                $kids = $children[$row->id] ?? [];
                $kids === [] || $out[] = new MenuLink($made->label, null, $kids); // a heading with nothing under it is not shown
            } else {
                $out[] = $made;
            }
        }

        return $out;
    }
}
