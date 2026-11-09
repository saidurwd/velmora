<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The header and footer menus. An item links to a CMS page, to a site path or https address, or is a group heading with child links (one level).
 * New items start hidden. This is site configuration, not a record, so items may be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('menu', 8);                                                         // header | footer
            $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->cascadeOnDelete();
            $table->string('kind', 8);                                                         // page | url | group
            $table->foreignId('page_id')->nullable()->constrained('cms_pages')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['menu', 'is_active', 'parent_id', 'sort_order'], 'cms_menu_items_render_index'); // the storefront: active items of a menu in order
        });

        DB::statement("ALTER TABLE cms_menu_items ADD CONSTRAINT cms_menu_items_menu_check CHECK (menu IN ('header','footer'))");
        DB::statement("ALTER TABLE cms_menu_items ADD CONSTRAINT cms_menu_items_kind_check CHECK (kind IN ('page','url','group'))");
        DB::statement("ALTER TABLE cms_menu_items ADD CONSTRAINT cms_menu_items_page_check CHECK ((kind = 'page') = (page_id IS NOT NULL))");
        DB::statement("ALTER TABLE cms_menu_items ADD CONSTRAINT cms_menu_items_shape_check CHECK (kind <> 'group' OR parent_id IS NULL)");   // a heading is never under another

        // A CHECK cannot read other rows (nor the auto-increment id), so the parent rule is a trigger: a parent is a top-level heading of the SAME menu, which also rules out a link under itself.
        $guard = "IF NEW.parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM cms_menu_items p WHERE p.id = NEW.parent_id AND p.kind = 'group' AND p.parent_id IS NULL AND p.menu = NEW.menu) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cms_menu_items: the parent must be a top-level heading of the same menu'; END IF";
        DB::unprepared("CREATE TRIGGER `cms_menu_items_parent_insert` BEFORE INSERT ON `cms_menu_items` FOR EACH ROW BEGIN {$guard}; END");
        DB::unprepared("CREATE TRIGGER `cms_menu_items_parent_update` BEFORE UPDATE ON `cms_menu_items` FOR EACH ROW BEGIN {$guard}; END");

        Schema::create('cms_menu_item_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('cms_menu_items')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label', 120);
            $table->string('url', 500)->nullable();                                            // only for kind = url; a language without one uses the default language's

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['item_id', 'locale']);                                             // one text per language; the item's labels
        });
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_menu_items_parent_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_menu_items_parent_insert`');
        Schema::dropIfExists('cms_menu_item_translations');
        Schema::dropIfExists('cms_menu_items');
    }
};
