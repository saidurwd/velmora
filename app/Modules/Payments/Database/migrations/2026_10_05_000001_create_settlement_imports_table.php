<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per settlement file that was checked against our records. Never changed or deleted.
        Schema::create('settlement_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('provider', 30);
            $table->char('file_hash', 64);                             // sha256 of the file: the same file is checked once
            $table->dateTime('period_from', 6);                        // the period the file claims to cover (our attempts verified in it must appear in the file)
            $table->dateTime('period_to', 6);
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('matched_count');                  // rows that agree with our records
            $table->unsignedInteger('problem_count');                  // rows and missing payments that became reconciliation exceptions
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('imported_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['provider', 'file_hash']);                 // the same file entered twice is refused, even by two people at once
            $table->index(['provider', 'period_to']);                  // the import history per provider
        });

        DB::statement('ALTER TABLE settlement_imports ADD CONSTRAINT settlement_imports_check CHECK (period_from <= period_to AND matched_count <= row_count)');
        AppendOnly::protect('settlement_imports');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `settlement_imports_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `settlement_imports_no_update`');
        Schema::dropIfExists('settlement_imports');
    }
};
