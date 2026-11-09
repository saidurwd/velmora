<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** Moves a section one place up or down: the rows are locked, the order is made 1, 2, 3 … again and two neighbours swap, so two people moving at once cannot leave a duplicate or a gap. */
final class MoveHomeSection
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /** @throws CmsException */
    public function handle(Actor $actor, string $publicId, string $direction): void
    {
        in_array($direction, ['up', 'down'], true) || throw CmsException::homeInvalid();

        $moved = Transactions::run(function () use ($actor, $publicId, $direction): bool {
            DB::table('cms_home_sections')->lockForUpdate()->pluck('id');
            $item = DB::table('cms_home_sections')->where('public_id', $publicId)->first() ?? throw CmsException::homeNotFound();

            $order = DB::table('cms_home_sections')->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $at = (int) array_search((int) $item->id, $order, true);
            $to = $direction === 'up' ? $at - 1 : $at + 1;
            if (isset($order[$to])) {
                [$order[$at], $order[$to]] = [$order[$to], $order[$at]];
            }
            foreach ($order as $index => $id) {
                DB::table('cms_home_sections')->where('id', $id)->update(['sort_order' => $index + 1]);
            }
            $this->audit->record('cms.home_section_moved', 'home_section', [], ['direction' => $direction], $actor, (int) $item->id);

            return isset($order[$to]);
        });
        $moved && $this->cache->bump();
    }
}
