<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('status', 12)->default('draft');
            $table->dateTime('publish_at', 6)->nullable();      // scheduled go-live (UTC)
            $table->dateTime('published_at', 6)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->index(['status', 'deleted_at']); // footer/sitemap: published pages
        });

        DB::statement("ALTER TABLE cms_pages ADD CONSTRAINT cms_pages_status_check CHECK (status IN ('draft','published'))");

        Schema::create('cms_page_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 200);
            $table->string('slug', 190);
            $table->mediumText('body')->nullable();            // sanitised HTML
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps(6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['page_id', 'locale']);
            $table->unique(['locale', 'slug']); // public lookup: /{locale}/pages/{slug}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_page_translations');
        Schema::dropIfExists('cms_pages');
    }
};
