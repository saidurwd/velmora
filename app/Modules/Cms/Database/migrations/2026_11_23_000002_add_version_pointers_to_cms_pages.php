<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand step: where each page's newest draft, live version and scheduled version are.
 * `publish_at` from now on means "the scheduled version goes live at this time".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->foreignId('draft_version_id')->nullable()->constrained('cms_page_versions')->restrictOnDelete();
            $table->foreignId('live_version_id')->nullable()->constrained('cms_page_versions')->restrictOnDelete();
            $table->foreignId('scheduled_version_id')->nullable()->constrained('cms_page_versions')->restrictOnDelete();

            $table->index('publish_at', 'cms_pages_publish_at_index'); // the every-minute job: which scheduled pages are due?
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropIndex('cms_pages_publish_at_index');
            $table->dropConstrainedForeignId('scheduled_version_id');
            $table->dropConstrainedForeignId('live_version_id');
            $table->dropConstrainedForeignId('draft_version_id');
        });
    }
};
