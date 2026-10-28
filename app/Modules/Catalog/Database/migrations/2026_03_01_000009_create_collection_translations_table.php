<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 160);
            $table->string('slug', 190);
            $table->text('description')->nullable();
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps(6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['collection_id', 'locale']);  // one row per language
            $table->unique(['locale', 'slug']);  // slug lookup per language (BD-30)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_translations');
    }
};
