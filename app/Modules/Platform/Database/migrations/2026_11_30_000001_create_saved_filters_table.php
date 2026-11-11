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
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('user_id');           // the staff member who owns it (no foreign key: the identity module owns users)
            $table->string('screen', 30);                    // which list, from the allow-list in code
            $table->string('name', 60);
            $table->string('query', 500);                    // the filters of that list, as a sorted query string, only keys the screen allows
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            // "a person's filters for one screen": the unique key also stops the same name twice
            $table->unique(['user_id', 'screen', 'name']);
        });

        DB::statement('ALTER TABLE saved_filters ADD CONSTRAINT saved_filters_text_check CHECK (CHAR_LENGTH(name) > 0 AND CHAR_LENGTH(query) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
