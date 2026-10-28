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
        Schema::create('catalog_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('filename', 255);
            $table->string('stored_path', 255);
            $table->boolean('dry_run')->default(true);
            $table->string('status', 12)->default('queued');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('results')->nullable();            // capped list of row outcomes with error keys (rendered per locale)
            $table->string('failure', 500)->nullable();     // whole-file problem (bad encoding, missing columns)
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->timestamps(6);

            $table->index('created_at'); // "recent imports" list
        });

        DB::statement("ALTER TABLE catalog_imports ADD CONSTRAINT catalog_imports_status_check CHECK (status IN ('queued','running','done','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_imports');
    }
};
