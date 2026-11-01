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
    /** A count is made, handed in, and approved or refused by a second person (or approved at once when nothing differs). */
    private const FLOW = ['open' => ['submitted', 'approved', 'cancelled'], 'submitted' => ['approved', 'rejected']];

    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('status', 12)->default('open');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('submitted_at', 6)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps(6);

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'created_at']);                   // counts waiting for a decision
            $table->index('location_id');
        });

        // one count at a time per place, so two counts can never correct the same shelf twice
        DB::statement("ALTER TABLE stock_counts ADD COLUMN active_marker BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('open','submitted'), location_id, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE stock_counts ADD UNIQUE INDEX stock_counts_one_active_per_location (active_marker)');
        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_check CHECK (status IN ('open','submitted','approved','rejected','cancelled'))");

        AppendOnly::protect('stock_counts', ['active_marker', 'status', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_note', 'updated_at']);
        StatusFlow::install('stock_counts', 'status', self::FLOW);

        // the two-person rule is the database's too: whoever handed the count in cannot decide on it (a count with no differences is approved by nobody).
        // A trigger, not a CHECK: MariaDB does not allow a CHECK on a column whose foreign key sets it to NULL.
        DB::unprepared("CREATE TRIGGER `stock_counts_two_people` BEFORE UPDATE ON `stock_counts` FOR EACH ROW BEGIN IF NEW.decided_by IS NOT NULL AND NEW.submitted_by IS NOT NULL AND NEW.decided_by = NEW.submitted_by THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_counts: a different person must decide'; END IF; END");

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('count_id')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->integer('expected_qty');                           // what the books said when the count began (a guide for the counter)
            $table->integer('counted_qty')->nullable();                // what was counted
            $table->integer('system_qty')->nullable();                 // what the books said when the count was handed in
            $table->integer('variance')->nullable();                   // counted − system at that moment: the correction a person approves

            $table->unique(['count_id', 'variant_id']);                // one line per product
            $table->index('variant_id');
        });

        DB::statement('ALTER TABLE stock_count_lines ADD CONSTRAINT stock_count_lines_check CHECK (expected_qty >= 0 AND (counted_qty IS NULL OR counted_qty >= 0) AND (variance IS NULL OR variance = counted_qty - system_qty))');
        AppendOnly::protect('stock_count_lines', ['counted_qty', 'system_qty', 'variance']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_count_lines_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_count_lines_no_update`');
        Schema::dropIfExists('stock_count_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_counts_two_people`');
        StatusFlow::remove('stock_counts');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_counts_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `stock_counts_no_update`');
        Schema::dropIfExists('stock_counts');
    }
};
