<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Every save of a page is one immutable version. Nothing here is ever updated or deleted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->restrictOnDelete();
            $table->unsignedInteger('number');                  // 1, 2, 3 … per page
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->unique(['page_id', 'number']);              // the version list of one page, newest first; one writer per number
        });

        Schema::create('cms_page_version_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('cms_page_versions')->restrictOnDelete();
            $table->string('locale', 5);
            $table->string('title', 200);
            $table->string('slug', 190);
            $table->mediumText('body')->nullable();             // sanitised HTML
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['version_id', 'locale']);
            $table->index(['locale', 'slug']);                  // is a draft slug already taken by another page?
        });

        DB::statement('ALTER TABLE cms_page_versions ADD CONSTRAINT cms_page_versions_number_check CHECK (number >= 1)');
        AppendOnly::protect('cms_page_versions');
        AppendOnly::protect('cms_page_version_translations');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_page_version_translations_no_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_page_version_translations_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_page_versions_no_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_page_versions_no_delete`');
        Schema::dropIfExists('cms_page_version_translations');
        Schema::dropIfExists('cms_page_versions');
    }
};
