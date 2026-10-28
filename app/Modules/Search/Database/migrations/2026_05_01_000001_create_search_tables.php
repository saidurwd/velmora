<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per live product per language. `title` and `body` hold NORMALISED text (see TextNormalizer), never display text.
        Schema::create('search_index', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->string('locale', 5);
            $table->string('title', 255);                 // normalised product name in this language
            $table->mediumText('body');                   // normalised description, brand, categories, tags and names in the other languages
            $table->string('display_name', 255);          // as shown in suggestions
            $table->string('slug', 190);
            $table->timestamps(6);

            $table->primary(['product_id', 'locale']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->fullText(['title', 'body']);          // ranked word / prefix search
            $table->fullText('title');                    // title-only relevance for ranking
            $table->index(['locale', 'title']);           // exact and prefix title matches
        });

        Schema::create('search_skus', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->string('sku_norm', 64);               // lower-case SKU
            $table->primary(['product_id', 'sku_norm']);
            $table->index('sku_norm');                    // exact / prefix SKU lookup
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        // Vocabulary of indexed words, used for "did you mean" (FULLTEXT itself has no typo tolerance).
        Schema::create('search_terms', function (Blueprint $table): void {
            $table->string('term', 40)->primary();
            $table->unsignedSmallInteger('length');
            $table->index(['length', 'term']);            // candidate lookup by length around the typed word
        });

        Schema::create('search_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 80);
            $table->string('synonym', 80);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);
            $table->unique(['term', 'synonym']);
            $table->index('synonym');                     // lookups run in both directions
        });

        // Zero-result tracking for merchandising. Deliberately has no user, session, IP or cookie columns.
        Schema::create('search_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 120);
            $table->string('locale', 5);
            $table->unsignedInteger('result_count');
            $table->dateTime('created_at', 6);
            $table->index(['result_count', 'created_at']);  // "no-result searches, most recent / most frequent"
            $table->index('created_at');                    // retention prune
        });
    }

    public function down(): void
    {
        foreach (['search_queries', 'search_synonyms', 'search_terms', 'search_skus', 'search_index'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
