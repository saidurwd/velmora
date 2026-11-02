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
        // Every attempt to download, granted or refused. Never changed or deleted. The signed address that was handed out is NEVER stored.
        Schema::create('download_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entitlement_id')->constrained('entitlements')->restrictOnDelete();
            $table->unsignedBigInteger('version_id')->nullable();      // the file version handed out (granted only)
            $table->unsignedBigInteger('user_id')->nullable();         // who was signed in (NULL: a guest with an order link and a code)
            $table->string('outcome', 16);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('version_id')->references('id')->on('digital_asset_versions')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['entitlement_id', 'created_at']);           // the history of one right: what staff look at when a customer disputes a download
            $table->index('created_at');                               // pruning old rows by age, and "downloads today"
        });
        DB::statement("ALTER TABLE download_logs ADD CONSTRAINT download_logs_check CHECK (outcome IN ('granted','not_owner','revoked','expired','limit_reached','no_file') AND (outcome <> 'granted' OR version_id IS NOT NULL))");
        AppendOnly::protect('download_logs');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `download_logs_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `download_logs_no_update`');
        Schema::dropIfExists('download_logs');
    }
};
