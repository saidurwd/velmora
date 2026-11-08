<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate step: every existing page becomes version 1 of itself. Safe to run twice (pages that already
 * have a draft version are skipped). Forward-only: the version rows are history and are kept on rollback.
 *
 * Published and due        -> live version 1
 * Published, future time   -> not live yet; version 1 is scheduled for that time
 * Draft                    -> draft version 1, no schedule
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');

        DB::table('cms_pages')->whereNull('draft_version_id')->orderBy('id')->each(function ($page) use ($now): void {
            $translations = DB::table('cms_page_translations')->where('page_id', $page->id)->get();
            if ($translations->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($page, $translations, $now): void {
                $versionId = DB::table('cms_page_versions')->insertGetId([
                    'page_id' => $page->id, 'number' => 1, 'created_by' => $page->created_by, 'created_at' => $page->created_at ?? $now,
                ]);

                DB::table('cms_page_version_translations')->insert($translations->map(fn ($t) => [
                    'version_id' => $versionId, 'locale' => $t->locale, 'title' => $t->title, 'slug' => $t->slug, 'body' => $t->body,
                    'seo_title' => $t->seo_title, 'seo_description' => $t->seo_description, 'created_at' => $t->created_at ?? $now,
                ])->all());

                $scheduledLater = $page->status === 'published' && $page->publish_at !== null && $page->publish_at > $now;
                $live = $page->status === 'published' && ! $scheduledLater;

                DB::table('cms_pages')->where('id', $page->id)->update([
                    'draft_version_id' => $versionId,
                    'live_version_id' => $live ? $versionId : null,
                    'scheduled_version_id' => $scheduledLater ? $versionId : null,
                    'status' => $live ? 'published' : 'draft',
                    'publish_at' => $scheduledLater ? $page->publish_at : null,
                ]);
            });
        });
    }

    public function down(): void
    {
        // Forward-only: version history is kept.
    }
};
