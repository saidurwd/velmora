<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A changed slug keeps working: the old address answers with a 301 to the entity's current address.
        Schema::create('slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 20);                 // product | category | brand | collection | page
            $table->unsignedBigInteger('entity_id');
            $table->string('locale', 5);
            $table->string('old_slug', 190);
            $table->dateTime('created_at', 6);

            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['entity_type', 'locale', 'old_slug']);   // the lookup on a request that found no page
            $table->index(['entity_type', 'entity_id']);             // tidy-up when an entity is renamed again
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
    }
};
