<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 50)->default('app');
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->boolean('is_secret')->default(false); // value stored encrypted
            $table->timestamps(6);

            // lookup by (scope, key)
            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
