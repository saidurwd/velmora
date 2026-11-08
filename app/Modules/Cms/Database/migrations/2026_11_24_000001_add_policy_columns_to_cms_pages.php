<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A page can be THE terms (or privacy, returns, shipping, COD) page. Ordinary pages keep kind NULL. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->string('kind', 12)->nullable()->unique('cms_pages_kind_unique');            // one page per kind; NULLs may repeat
            $table->boolean('schedule_requires_acceptance')->default(true);                       // what a scheduled release will ask of customers
        });

        $list = "'terms','privacy','returns','shipping','cod'";   // written out: a migration must not change when the enum does
        DB::statement("ALTER TABLE cms_pages ADD CONSTRAINT cms_pages_kind_check CHECK (kind IS NULL OR kind IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cms_pages DROP CONSTRAINT cms_pages_kind_check');
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropUnique('cms_pages_kind_unique');
            $table->dropColumn(['kind', 'schedule_requires_acceptance']);
        });
    }
};
