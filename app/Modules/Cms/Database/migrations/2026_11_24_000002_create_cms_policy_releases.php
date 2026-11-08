<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row each time a policy version goes live. The newest effective row of a kind is the policy in force. Append-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_policy_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('cms_pages')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('cms_page_versions')->restrictOnDelete();
            $table->string('kind', 12);
            $table->dateTime('effective_at', 6);                 // UTC: the moment the version went live
            $table->boolean('acceptance_required');              // false = a minor edit: people who accepted before are still fine
            $table->unsignedBigInteger('released_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['kind', 'effective_at', 'id'], 'cms_policy_releases_kind_effective_index'); // the policy in force for a kind
        });

        $list = "'terms','privacy','returns','shipping','cod'";   // written out: a migration must not change when the enum does
        DB::statement("ALTER TABLE cms_policy_releases ADD CONSTRAINT cms_policy_releases_kind_check CHECK (kind IN ({$list}))");
        AppendOnly::protect('cms_policy_releases');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_policy_releases_no_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `cms_policy_releases_no_delete`');
        Schema::dropIfExists('cms_policy_releases');
    }
};
