<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use Illuminate\Support\Facades\DB;

/**
 * Where a language other than the default is missing text the default language has, in what visitors can see right now.
 * A field is missing when the default language has it and the other language's row is absent or blank. Nothing is stored: each type is one live read.
 */
final class TranslationGaps
{
    public const LIMIT = 200;

    /**
     * Type => table of translations, the parent's table, the key joining them, fields that matter, the scope of "visible", the route and the parent's key for the edit link.
     *
     * @var array<string, array{table: string, parent: string, key: string, fields: list<string>, where: string, route: string}>
     */
    private const TYPES = [
        'pages' => ['table' => 'cms_page_translations', 'parent' => 'cms_pages', 'key' => 'page_id', 'fields' => ['title', 'body'], 'where' => "p.status = 'published' AND p.deleted_at IS NULL", 'route' => 'admin.cms.pages.edit'],
        'banners' => ['table' => 'banner_translations', 'parent' => 'banners', 'key' => 'banner_id', 'fields' => ['title', 'subtitle', 'cta_label', 'alt'], 'where' => 'p.is_active = 1 AND p.deleted_at IS NULL', 'route' => 'admin.cms.banners.edit'],
        'faq' => ['table' => 'faq_entry_translations', 'parent' => 'faq_entries', 'key' => 'faq_entry_id', 'fields' => ['question', 'answer'], 'where' => 'p.is_published = 1', 'route' => 'admin.cms.faq.edit'],
        'home_sections' => ['table' => 'cms_home_section_translations', 'parent' => 'cms_home_sections', 'key' => 'section_id', 'fields' => ['title', 'body'], 'where' => 'p.is_active = 1', 'route' => 'admin.cms.home.edit'],
        'menu_items' => ['table' => 'cms_menu_item_translations', 'parent' => 'cms_menu_items', 'key' => 'item_id', 'fields' => ['label'], 'where' => 'p.is_active = 1', 'route' => 'admin.cms.menus.edit'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @return array<string, array{live: int, missing: int, rows: list<array{public_id: string, title: string, fields: list<string>}>, capped: bool, route: string}>
     */
    public function forLocale(string $locale): array
    {
        $default = (string) config('app.default_locale');
        $out = [];

        foreach (self::TYPES as $type => $def) {
            $base = fn () => DB::table($def['parent'].' as p')
                ->join($def['table'].' as d', fn ($j) => $j->on('d.'.$def['key'], '=', 'p.id')->where('d.locale', '=', $default))
                ->leftJoin($def['table'].' as t', fn ($j) => $j->on('t.'.$def['key'], '=', 'p.id')->where('t.locale', '=', $locale))
                ->whereRaw($def['where']);

            $flags = array_map(fn (string $f) => "(d.{$f} IS NOT NULL AND d.{$f} <> '' AND (t.id IS NULL OR t.{$f} IS NULL OR t.{$f} = '')) AS miss_{$f}", $def['fields']);
            $any = implode(' OR ', array_map(fn (string $f) => "(d.{$f} IS NOT NULL AND d.{$f} <> '' AND (t.id IS NULL OR t.{$f} IS NULL OR t.{$f} = ''))", $def['fields']));
            $title = $def['fields'][0];

            $rows = $base()->whereRaw("({$any})")->orderBy('p.id')->limit(self::LIMIT + 1)->selectRaw('p.public_id AS public_id, d.'.$title.' AS title, '.implode(', ', $flags))->get();
            $capped = $rows->count() > self::LIMIT;

            $list = [];
            foreach ($rows->take(self::LIMIT) as $r) {
                $list[] = [
                    'public_id' => (string) $r->public_id, 'title' => mb_substr(strip_tags((string) $r->title), 0, 120),
                    'fields' => array_values(array_filter($def['fields'], fn (string $f) => (bool) $r->{'miss_'.$f})),
                ];
            }

            $out[$type] = [
                'live' => $base()->count(),
                'missing' => $capped ? $base()->whereRaw("({$any})")->count() : $rows->count(),
                'rows' => $list,
                'capped' => $capped,
                'route' => $def['route'],
            ];
        }

        return $out;
    }
}
