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
        // Closure table (ADR-026): one row per (ancestor, descendant) pair, including the node itself at depth 0.
        Schema::create('category_closure', function (Blueprint $table): void {
            $table->foreignId('ancestor_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedTinyInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index(['descendant_id', 'depth']); // breadcrumb / ancestors of a node
        });

        DB::statement('ALTER TABLE category_closure ADD CONSTRAINT category_closure_depth_check CHECK (depth <= 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('category_closure');
    }
};
