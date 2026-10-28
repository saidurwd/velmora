<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 60);
            $table->string('native_name', 60);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('direction', 3)->default('ltr');
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
