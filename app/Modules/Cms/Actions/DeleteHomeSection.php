<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

final class DeleteHomeSection
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /** @throws CmsException */
    public function handle(Actor $actor, string $publicId): void
    {
        Transactions::run(function () use ($actor, $publicId): void {
            DB::table('cms_home_sections')->lockForUpdate()->pluck('id');
            $item = DB::table('cms_home_sections')->where('public_id', $publicId)->first() ?? throw CmsException::homeNotFound();
            DB::table('cms_home_sections')->where('id', $item->id)->delete();
            $this->audit->record('cms.home_section_deleted', 'home_section', ['type' => $item->type], [], $actor, (int) $item->id);
        });
        $this->cache->bump();
    }
}
