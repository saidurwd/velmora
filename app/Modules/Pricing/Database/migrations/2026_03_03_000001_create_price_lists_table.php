<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->char('currency', 3)->default('BDT');
            $table->boolean('is_default')->default(false);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
