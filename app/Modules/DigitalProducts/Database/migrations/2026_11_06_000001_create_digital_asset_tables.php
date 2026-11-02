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
        // The downloadable file of one digital product variant. The files themselves live in the private bucket, never in the database.
        Schema::create('digital_assets', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('variant_id');
            $table->unsignedInteger('download_limit')->nullable();     // null = the shop's default; a number overrides it for this product
            $table->unsignedSmallInteger('access_days')->nullable();   // null = no expiry; days after purchase that the customer may download
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('variant_id');                              // one file set per variant; also finds a variant's asset
        });
        DB::statement('ALTER TABLE digital_assets ADD CONSTRAINT digital_assets_check CHECK ((download_limit IS NULL OR download_limit >= 1) AND (access_days IS NULL OR access_days >= 1))');

        // Every upload is a new version, kept for good. The newest version is the one customers get.
        Schema::create('digital_asset_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('digital_assets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_key', 190);                        // random; made by us, never from the uploaded name
            $table->string('original_filename', 190);                  // what the customer's file is called when they save it
            $table->string('mime', 100);                               // from the file's own bytes
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);                                // lets anyone prove the file has not changed
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['asset_id', 'version']);                   // numbers are never reused, even by two uploads at once; the newest version of an asset
            $table->unique('storage_key');                             // a stored file belongs to exactly one version
        });
        DB::statement('ALTER TABLE digital_asset_versions ADD CONSTRAINT digital_asset_versions_check CHECK (version >= 1 AND size_bytes >= 1)');
        AppendOnly::protect('digital_asset_versions');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `digital_asset_versions_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `digital_asset_versions_no_update`');
        Schema::dropIfExists('digital_asset_versions');
        Schema::dropIfExists('digital_assets');
    }
};
