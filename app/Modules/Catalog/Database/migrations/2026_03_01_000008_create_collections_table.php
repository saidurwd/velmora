<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
