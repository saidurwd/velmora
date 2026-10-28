<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\DB;

/** Remembers retired slugs so old links, bookmarks and search results keep working (301). Shared by Catalog and Cms. */
final class SlugRedirects
{
    public function record(string $type, int $entityId, string $locale, string $oldSlug): void
    {
        if ($oldSlug === '') {
            return;
        }

        DB::table('slug_redirects')->updateOrInsert(
            ['entity_type' => $type, 'locale' => $locale, 'old_slug' => $oldSlug],
            ['entity_id' => $entityId, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u')],
        );
    }

    /** A slug that is live again must not also redirect. */
    public function forget(string $type, string $locale, string $slug): void
    {
        DB::table('slug_redirects')->where('entity_type', $type)->where('locale', $locale)->where('old_slug', $slug)->delete();
    }

    public function resolve(string $type, string $locale, string $slug): ?int
    {
        $id = DB::table('slug_redirects')->where('entity_type', $type)->where('locale', $locale)->where('old_slug', $slug)->value('entity_id');

        return $id === null ? null : (int) $id;
    }
}
