<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The last result of each data-integrity check. Checks only read business tables; this is the one place they write. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_check_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();                      // upserted by code
            $table->string('status', 8);                               // ok | problem | error
            $table->unsignedInteger('problems')->default(0);
            $table->json('samples')->nullable();                       // a few examples, never more than the configured sample size
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->dateTime('ran_at', 6);
        });

        DB::statement("ALTER TABLE integrity_check_runs ADD CONSTRAINT integrity_check_runs_status_check CHECK (status IN ('ok','problem','error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_check_runs');
    }
};
