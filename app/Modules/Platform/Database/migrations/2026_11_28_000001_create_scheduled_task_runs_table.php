<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row per scheduled task: when the scheduler last started and finished it. A task that hands work to the queue is "finished" once handed over. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();                     // upserted by name on every start/finish
            $table->dateTime('last_started_at', 6)->nullable();
            $table->dateTime('last_finished_at', 6)->nullable();
            $table->string('last_status', 8);                          // started | ok | failed | skipped
            $table->string('last_error', 255)->nullable();             // first line, secrets removed
            $table->unsignedInteger('last_runtime_ms')->nullable();
            $table->dateTime('updated_at', 6);
        });

        DB::statement("ALTER TABLE scheduled_task_runs ADD CONSTRAINT scheduled_task_runs_status_check CHECK (last_status IN ('started','ok','failed','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
