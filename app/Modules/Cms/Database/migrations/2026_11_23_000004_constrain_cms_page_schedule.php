<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Contract step: a schedule is a version plus a time, never one without the other. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cms_pages')->whereNull('scheduled_version_id')->whereNotNull('publish_at')->update(['publish_at' => null]); // leftovers of the old meaning
        DB::statement('ALTER TABLE cms_pages ADD CONSTRAINT cms_pages_schedule_check CHECK ((scheduled_version_id IS NULL) = (publish_at IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cms_pages DROP CONSTRAINT cms_pages_schedule_check');
    }
};
