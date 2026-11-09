<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Support\SafeLink;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An item of the header or footer menu. A page link follows its page; a custom link must be a site path or an https address (per language);
 * a group is a heading for child links, one level deep. A new item starts hidden: only someone who may publish can show or hide one.
 */
final class SaveMenuItem
{
    public const MENUS = ['header', 'footer'];

    public const KINDS = ['page', 'url', 'group'];

    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /**
     * @param  array<string, array{label?: string|null, url?: string|null}>  $translations
     * @return string the item's public id
     *
     * @throws CmsException
     */
    public function handle(Actor $actor, ?string $publicId, string $menu, string $kind, ?string $parentPublicId, ?string $pagePublicId, ?bool $active, bool $canShow, array $translations): string
    {
        in_array($menu, self::MENUS, true) && in_array($kind, self::KINDS, true) || throw CmsException::menuInvalid();

        $default = (string) config('app.default_locale');
        $clean = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $label = mb_substr(trim(BanglaText::input((string) ($translations[$locale]['label'] ?? ''))), 0, 120);
            $url = trim((string) ($translations[$locale]['url'] ?? ''));
            if ($label === '') {
                continue;
            }
            if ($kind === 'url' && $url !== '' && ! SafeLink::isSafe($url)) {
                throw CmsException::unsafeLink();
            }
            $clean[(string) $locale] = [$label, $kind === 'url' && $url !== '' ? $url : null];
        }
        isset($clean[$default]) || throw CmsException::menuDefaultRequired($default);
        $kind !== 'url' || ($clean[$default][1] !== null) || throw CmsException::unsafeLink();

        $id = Transactions::run(function () use ($actor, $publicId, $menu, $kind, $parentPublicId, $pagePublicId, $active, $canShow, $clean): int {
            $existing = $publicId === null ? null : (DB::table('cms_menu_items')->where('public_id', $publicId)->lockForUpdate()->first() ?? throw CmsException::menuNotFound());
            $menu = $existing->menu ?? $menu;                                              // an item stays in its menu
            DB::table('cms_menu_items')->where('menu', $menu)->lockForUpdate()->pluck('id');   // one editor at a time per menu

            $pageId = null;
            if ($kind === 'page') {
                $pageId = DB::table('cms_pages')->where('public_id', (string) $pagePublicId)->whereNull('deleted_at')->value('id') ?? throw CmsException::menuPageMissing();
            }

            $parentId = null;
            if ($parentPublicId !== null && $parentPublicId !== '') {
                $parent = DB::table('cms_menu_items')->where('public_id', $parentPublicId)->first();
                ($parent !== null && $parent->menu === $menu && $parent->kind === 'group' && $parent->parent_id === null && $kind !== 'group' && $parent->id !== ($existing->id ?? 0)) || throw CmsException::menuParentInvalid();
                $parentId = (int) $parent->id;
            }

            if ($existing !== null && ($kind !== 'group') && DB::table('cms_menu_items')->where('parent_id', $existing->id)->exists()) {
                throw CmsException::menuHasChildren(); // a heading with links under it stays a heading
            }

            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $changes = ['kind' => $kind, 'page_id' => $pageId, 'parent_id' => $parentId, 'updated_at' => $now];
            if ($existing === null) {
                $sort = ((int) DB::table('cms_menu_items')->where('menu', $menu)->where('parent_id', $parentId)->max('sort_order')) + 1;
                $id = DB::table('cms_menu_items')->insertGetId($changes + ['public_id' => strtolower((string) Str::ulid()), 'menu' => $menu, 'sort_order' => $sort, 'is_active' => $canShow && $active === true, 'created_by' => $actor->id, 'created_at' => $now]);
            } else {
                $id = (int) $existing->id;
                $canShow && $active !== null && $changes['is_active'] = $active;
                if ((int) ($existing->parent_id ?? 0) !== (int) $parentId) {
                    $changes['sort_order'] = ((int) DB::table('cms_menu_items')->where('menu', $menu)->where('parent_id', $parentId)->max('sort_order')) + 1; // to the end of its new place
                }
                DB::table('cms_menu_items')->where('id', $id)->update($changes);
            }

            DB::table('cms_menu_item_translations')->where('item_id', $id)->whereNotIn('locale', array_keys($clean))->delete();
            foreach ($clean as $locale => [$label, $url]) {
                DB::table('cms_menu_item_translations')->updateOrInsert(['item_id' => $id, 'locale' => $locale], ['label' => $label, 'url' => $url]);
            }
            $this->audit->record($existing === null ? 'cms.menu_item_created' : 'cms.menu_item_updated', 'menu_item', [], ['menu' => $menu, 'kind' => $kind, 'languages' => array_keys($clean)], $actor, $id);

            return $id;
        });
        $this->cache->bump();

        return (string) DB::table('cms_menu_items')->where('id', $id)->value('public_id');
    }
}
