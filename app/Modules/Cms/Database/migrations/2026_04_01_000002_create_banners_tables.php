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
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('placement', 30);
            $table->string('link_url', 500)->nullable();      // site-relative path or https URL, validated on write
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at', 6)->nullable();     // UTC
            $table->dateTime('ends_at', 6)->nullable();       // UTC, exclusive
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->index(['placement', 'is_active', 'sort_order']); // home page: active banners in order
        });

        DB::statement("ALTER TABLE banners ADD CONSTRAINT banners_placement_check CHECK (placement IN ('home_hero','home_strip'))");
        DB::statement('ALTER TABLE banners ADD CONSTRAINT banners_window_check CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('banner_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 160)->nullable();
            $table->string('subtitle', 255)->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('alt', 200)->nullable();           // per-language alt text (the image may contain Bangla text)
            $table->json('renditions')->nullable();           // {"800": "banners/…-800.webp", "1600": "…"} on the media disk
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps(6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['banner_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_translations');
        Schema::dropIfExists('banners');
    }
};
