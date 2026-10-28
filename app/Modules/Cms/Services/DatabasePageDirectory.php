<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Contracts\PageDirectory;
use App\Modules\Cms\DTOs\PageLink;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

final class DatabasePageDirectory implements PageDirectory
{
    public function __construct(private readonly Clock $clock) {}

    public function published(string $locale): array
    {
        return array_values(DB::table('cms_pages')->join('cms_page_translations', 'cms_page_translations.page_id', '=', 'cms_pages.id')
            ->whereNull('cms_pages.deleted_at')->where('cms_pages.status', 'published')
            ->where(fn ($q) => $q->whereNull('cms_pages.publish_at')->orWhere('cms_pages.publish_at', '<=', $this->clock->now()))
            ->where('cms_page_translations.locale', $locale)->orderBy('cms_page_translations.title')
            ->get(['cms_page_translations.slug', 'cms_page_translations.title'])
            ->map(fn ($r) => new PageLink((string) $r->slug, (string) $r->title, $locale))->all());
    }
}
