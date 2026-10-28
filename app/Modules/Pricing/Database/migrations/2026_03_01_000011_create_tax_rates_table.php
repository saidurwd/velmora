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
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_class_id')->constrained('tax_classes')->restrictOnDelete();
            $table->string('jurisdiction', 20)->default('BD');
            $table->unsignedSmallInteger('rate_bp'); // basis points: 1500 = 15%
            $table->date('valid_from');
            $table->date('valid_to')->default('9999-12-31'); // EXCLUSIVE end (period semantics): valid up to, not including; open-ended = far date
            $table->timestamps(6);

            // lookup: the rate in force for a class on a date
            $table->index(['tax_class_id', 'jurisdiction', 'valid_from']);
        });

        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_bp_check CHECK (rate_bp <= 10000)');
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_period_check CHECK (valid_to > valid_from)');

        // Two rates for the same class + jurisdiction can never overlap in time: enforced by the database (MariaDB WITHOUT OVERLAPS).
        DB::statement('ALTER TABLE tax_rates ADD PERIOD FOR validity(valid_from, valid_to)');
        DB::statement('ALTER TABLE tax_rates ADD UNIQUE KEY tax_rates_no_overlap (tax_class_id, jurisdiction, validity WITHOUT OVERLAPS)');

        // history is kept: a rate is closed by setting valid_to, never rewritten or deleted
        AppendOnly::protect('tax_rates', ['valid_to', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
