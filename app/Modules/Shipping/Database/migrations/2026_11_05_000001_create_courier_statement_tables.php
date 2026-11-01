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
        // One row per statement a courier sent, checked against our own records. Never changed or deleted.
        Schema::create('courier_statements', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('courier', 30);
            $table->string('statement_ref', 80);
            $table->char('file_hash', 64);                             // sha256 of the file: the same file is checked once
            $table->dateTime('period_from', 6);
            $table->dateTime('period_to', 6);
            $table->bigInteger('received_minor');                      // what the courier actually paid over
            $table->bigInteger('fee_minor');                           // what the courier kept for its own charges (delivery, returns)
            $table->char('currency', 3)->default('BDT');
            $table->unsignedInteger('line_count');
            $table->unsignedInteger('matched_count');
            $table->unsignedInteger('problem_count');                  // lines and missing parcels that became exceptions
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('imported_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['courier', 'statement_ref']);              // a statement number is entered once per courier (the cash record uses the same key)
            $table->unique(['courier', 'file_hash']);                  // the same file twice is refused, even by two people at once
            $table->index(['courier', 'period_to']);                   // the history per courier
        });

        DB::statement('ALTER TABLE courier_statements ADD CONSTRAINT courier_statements_check CHECK (period_from <= period_to AND received_minor >= 0 AND fee_minor >= 0 AND matched_count <= line_count)');
        AppendOnly::protect('courier_statements');

        Schema::create('courier_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('statement_id')->constrained('courier_statements')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->string('consignment_id', 80);
            $table->string('courier_status', 60);
            $table->bigInteger('cod_minor');                           // the cash the courier says it collected for the parcel
            $table->bigInteger('fee_minor');                           // what the courier charged for the parcel
            $table->dateTime('delivered_at', 6)->nullable();
            $table->string('result', 20);
            $table->dateTime('created_at', 6);

            $table->unique(['statement_id', 'consignment_id']);        // a parcel appears once on a statement
            $table->index('shipment_id');                              // which statements mention a parcel (what the courier charged for it)
        });

        DB::statement("ALTER TABLE courier_statement_lines ADD CONSTRAINT courier_statement_lines_check CHECK (cod_minor >= 0 AND fee_minor >= 0 AND result IN ('matched','unknown_parcel','status_mismatch','cod_mismatch','already_remitted'))");
        AppendOnly::protect('courier_statement_lines');
    }

    public function down(): void
    {
        foreach (['courier_statement_lines', 'courier_statements'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_delete`");
            DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_update`");
            Schema::dropIfExists($table);
        }
    }
};
