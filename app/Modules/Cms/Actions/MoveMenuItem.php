<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Moves an item one place up or down among its neighbours (same menu, same parent). The menu's rows are locked first, the order is made
 * 1, 2, 3 … again, and two neighbours swap, so two people moving at once can never leave a duplicate or a gap.
 */
final class MoveMenuItem
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /** @throws CmsException */
    public function handle(Actor $actor, string $publicId, string $direction): void
    {
        in_array($direction, ['up', 'down'], true) || throw CmsException::menuInvalid();

        $moved = Transactions::run(function () use ($actor, $publicId, $direction): bool {
            $item = DB::table('cms_menu_items')->where('public_id', $publicId)->first() ?? throw CmsException::menuNotFound();
            DB::table('cms_menu_items')->where('menu', $item->menu)->lockForUpdate()->pluck('id');

            $siblings = DB::table('cms_menu_items')->where('menu', $item->menu)->where('parent_id', $item->parent_id)->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $at = array_search((int) $item->id, $siblings, true);
            if ($at === false) {
                throw CmsException::menuNotFound();
            }
            $at = (int) $at;
            $to = $direction === 'up' ? $at - 1 : $at + 1;
            if (isset($siblings[$to])) {
                [$siblings[$at], $siblings[$to]] = [$siblings[$to], $siblings[$at]];
            }
            foreach ($siblings as $index => $id) {
                DB::table('cms_menu_items')->where('id', $id)->update(['sort_order' => $index + 1]);
            }
            $this->audit->record('cms.menu_item_moved', 'menu_item', [], ['direction' => $direction], $actor, (int) $item->id);

            return isset($siblings[$to]);
        });
        $moved && $this->cache->bump();
    }
}
