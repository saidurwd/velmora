<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The help centre: questions and answers in both languages, shown on /help. Plain text answers (line breaks kept), so nothing typed can be run as a page.
        Schema::create('faq_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('category', 20);                                                       // orders | delivery | payment | returns | account | other
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['is_published', 'category', 'sort_order']);                            // /help: published questions grouped by category in order
        });
        Schema::create('faq_entry_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faq_entry_id')->constrained('faq_entries')->cascadeOnDelete();
            $table->char('locale', 2);
            $table->string('question', 200);
            $table->text('answer');

            $table->unique(['faq_entry_id', 'locale']);                                           // one text per language
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_entry_translations');
        Schema::dropIfExists('faq_entries');
    }
};
