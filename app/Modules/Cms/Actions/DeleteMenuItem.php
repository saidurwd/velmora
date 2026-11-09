<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Removes an item, and the links under it when it is a group. */
final class DeleteMenuItem
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /** @throws CmsException */
    public function handle(Actor $actor, string $publicId): void
    {
        Transactions::run(function () use ($actor, $publicId): void {
            $item = DB::table('cms_menu_items')->where('public_id', $publicId)->first() ?? throw CmsException::menuNotFound();
            DB::table('cms_menu_items')->where('menu', $item->menu)->lockForUpdate()->pluck('id');
            $children = DB::table('cms_menu_items')->where('parent_id', $item->id)->count();
            DB::table('cms_menu_items')->where('id', $item->id)->delete();   // the links under a heading go with it (foreign key)
            $this->audit->record('cms.menu_item_deleted', 'menu_item', ['menu' => $item->menu, 'kind' => $item->kind], ['children' => $children], $actor, (int) $item->id);
        });
        $this->cache->bump();
    }
}
