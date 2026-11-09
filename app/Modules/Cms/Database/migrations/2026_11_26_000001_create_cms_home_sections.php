<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The blocks of the storefront home page, in order. With none visible the home page keeps its built-in layout.
 * New sections start hidden. Site configuration, not a record, so rows may be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_home_sections', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('type', 16);                                               // banner_hero | banner_strip | categories | collection | new_arrivals | popular | text
            $table->char('collection_ref', 26)->charset('ascii')->collation('ascii_bin')->nullable(); // the collection's public id (kind collection only); Catalog owns collections
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->dateTime('starts_at', 6)->nullable();                             // UTC
            $table->dateTime('ends_at', 6)->nullable();                               // UTC, exclusive
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['is_active', 'sort_order'], 'cms_home_sections_render_index'); // the home page: visible sections in order
        });

        DB::statement("ALTER TABLE cms_home_sections ADD CONSTRAINT cms_home_sections_type_check CHECK (type IN ('banner_hero','banner_strip','categories','collection','new_arrivals','popular','text'))");
        DB::statement("ALTER TABLE cms_home_sections ADD CONSTRAINT cms_home_sections_ref_check CHECK ((type = 'collection') = (collection_ref IS NOT NULL))");
        DB::statement('ALTER TABLE cms_home_sections ADD CONSTRAINT cms_home_sections_window_check CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('cms_home_section_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('cms_home_sections')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();                                         // sanitised HTML, text blocks only

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['section_id', 'locale']);                                 // one text per language
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_home_section_translations');
        Schema::dropIfExists('cms_home_sections');
    }
};
