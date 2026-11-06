<?php

declare(strict_types=1);

use App\Modules\Platform\Support\StatusFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** from => allowed next. The customer asks; staff approve or refuse; the customer may withdraw while it waits. */
    private const FLOW = ['requested' => ['approved', 'rejected', 'withdrawn']];

    public function up(): void
    {
        // A customer's request to cancel an order the shop has already taken money for (or confirmed). Approving it records the shop's agreement and changes nothing else:
        // refunding and cancelling are done by a person on the normal screens.
        Schema::create('order_cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 10)->default('requested');
            $table->string('open_key', 20)->nullable()->unique();      // the order's id while the request waits or is approved, NULL once refused or withdrawn: one open request per order
            $table->string('note', 255)->nullable();                    // the customer's own words (cleared when they are erased)
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at', 6)->nullable();
            $table->string('decision_note', 255)->nullable();           // what staff told the customer
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['status', 'id']);                            // the staff queue: waiting requests, oldest first
            $table->index(['order_id', 'id']);                          // one order's requests (the order page)
            $table->index(['user_id', 'id']);                           // one customer's requests (the data copy)
        });
        DB::statement("ALTER TABLE order_cancellation_requests ADD CONSTRAINT order_cancellation_requests_check CHECK (status IN ('requested','approved','rejected','withdrawn') AND (status <> 'rejected' OR decision_note IS NOT NULL))");
        StatusFlow::install('order_cancellation_requests', 'status', self::FLOW);
    }

    public function down(): void
    {
        StatusFlow::remove('order_cancellation_requests');
        Schema::dropIfExists('order_cancellation_requests');
    }
};
