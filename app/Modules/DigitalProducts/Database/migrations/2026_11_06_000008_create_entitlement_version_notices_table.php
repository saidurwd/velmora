<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "We told this customer about this new version." One row per customer and version, so a retry never tells them twice.
        Schema::create('entitlement_version_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entitlement_id')->constrained('entitlements')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('digital_asset_versions')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->unique(['entitlement_id', 'version_id']);          // one notice per customer and version, even if two workers send at once
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_version_notices');
    }
};
