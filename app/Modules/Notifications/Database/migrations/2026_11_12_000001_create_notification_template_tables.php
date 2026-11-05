<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per message the shop sends (a code like "order.placed.sms"). What it is for and which variables its text may use are decided in code (the template catalog);
        // staff only change the words.
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('channel', 5);                        // email | sms
            $table->string('kind', 13);                          // transactional | marketing
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_check CHECK (channel IN ('email','sms') AND kind IN ('transactional','marketing'))");

        // Every save of the words is a new version, never changed afterwards. The current words of a language are its highest version; "going back" is saving old words again.
        Schema::create('notification_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('notification_templates')->restrictOnDelete();
            $table->string('locale', 5);
            $table->unsignedInteger('version');
            $table->string('subject', 190)->nullable();          // email only
            $table->text('body');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['template_id', 'locale', 'version']);   // the current words of one language are the highest version; one row per version
        });
        AppendOnly::protect('notification_template_versions');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `notification_template_versions_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `notification_template_versions_no_update`');
        Schema::dropIfExists('notification_template_versions');
        Schema::dropIfExists('notification_templates');
    }
};
