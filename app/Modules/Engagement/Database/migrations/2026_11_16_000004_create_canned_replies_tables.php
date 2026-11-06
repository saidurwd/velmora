<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ready-made answers staff can start a reply from, in both languages. Plain text; {{ customer }} and {{ order }} are replaced when one is chosen.
        Schema::create('canned_replies', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['is_active', 'sort_order']);                                           // the picker on a ticket: active ones in order
        });
        Schema::create('canned_reply_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('canned_reply_id')->constrained('canned_replies')->cascadeOnDelete();
            $table->char('locale', 2);
            $table->string('title', 80);
            $table->text('body');

            $table->unique(['canned_reply_id', 'locale']);                                        // one text per language
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_reply_translations');
        Schema::dropIfExists('canned_replies');
    }
};
