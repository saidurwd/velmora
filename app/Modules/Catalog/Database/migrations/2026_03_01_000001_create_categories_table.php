<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->index(['parent_id', 'sort_order']); // children of a node, in order
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
