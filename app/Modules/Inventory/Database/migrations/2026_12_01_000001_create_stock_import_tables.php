<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A file is read and shown first (previewed), then applied once, then possibly undone by a compensating batch; nothing goes back. */
    private const FLOW = ['previewed' => ['applied'], 'applied' => ['rolled_back']];

    public function up(): void
    {
        Schema::create('stock_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('mode', 3);                                 // 'set' = the file says what is on hand, 'add' = the file says how many to add (or take away)
            $table->string('status', 12)->default('previewed');
            $table->string('file_name', 190)->nullable();
            $table->string('note', 255);                               // why: written into every ledger row
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->dateTime('applied_at', 6)->nullable();
            $table->unsignedBigInteger('rolled_back_by')->nullable();
            $table->dateTime('rolled_back_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('applied_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rolled_back_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['created_at', 'id']);                       // the list of imports, newest first
            $table->index('location_id');
        });

        DB::statement("ALTER TABLE stock_imports ADD CONSTRAINT stock_imports_check CHECK (mode IN ('set','add') AND status IN ('previewed','applied','rolled_back') AND error_count <= row_count AND unchanged_count <= row_count)");
        AppendOnly::protect('stock_imports', ['status', 'applied_by', 'applied_at', 'rolled_back_by', 'rolled_back_at', 'updated_at']);
        StatusFlow::install('stock_imports', 'status', self::FLOW);

        Schema::create('stock_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('stock_imports')->restrictOnDelete();
            $table->unsignedInteger('line');                           // the line in the file (the header is line 1)
            $table->string('sku', 64);
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('raw_quantity', 40)->nullable();            // the quantity exactly as typed in the file, for the error report
            $table->integer('quantity')->nullable();                   // what the file said ('set': the new on-hand; 'add': the change)
            $table->string('status', 9);                               // ok = will change stock, unchanged = already right, error = refused
            $table->string('error_code', 30)->nullable();
            $table->integer('before_on_hand')->nullable();             // what the books said at the preview
            $table->integer('delta')->nullable();                      // the change the preview worked out
            $table->integer('applied_delta')->nullable();              // the change that was really made
            $table->integer('after_on_hand')->nullable();              // on hand right after this row
            $table->string('rollback_state', 14)->nullable();          // reverted | kept_changed | kept_blocked

            $table->unique(['import_id', 'line']);                     // the rows of one import, in file order
            $table->index('variant_id');                               // "which imports touched this product"
        });

        DB::statement("ALTER TABLE stock_import_rows ADD CONSTRAINT stock_import_rows_check CHECK (status IN ('ok','unchanged','error') AND (status = 'error') = (error_code IS NOT NULL) AND (rollback_state IS NULL OR rollback_state IN ('reverted','kept_changed','kept_blocked')))");
        AppendOnly::protect('stock_import_rows', ['applied_delta', 'after_on_hand', 'rollback_state']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_import_rows_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_import_rows_no_update`');
        Schema::dropIfExists('stock_import_rows');
        StatusFlow::remove('stock_imports');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_imports_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_imports_no_update`');
        Schema::dropIfExists('stock_imports');
    }
};
