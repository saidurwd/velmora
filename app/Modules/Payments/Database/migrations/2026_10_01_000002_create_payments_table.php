<?php

declare(strict_types=1);

use App\Modules\Payments\Support\StatusFlow;
use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The payment's life. A paid payment never goes back; an expired one can only be looked at (a late success). */
    private const FLOW = [
        'initiated' => ['pending', 'paid', 'failed', 'cancelled', 'expired', 'needs_review'],
        'pending' => ['paid', 'failed', 'cancelled', 'expired', 'needs_review'],
        'failed' => ['pending'],                                      // the customer tries again
        'cancelled' => ['pending'],
        'expired' => ['needs_review'],                                // a success arrives after the time ran out
        'needs_review' => ['paid', 'failed', 'cancelled', 'expired'], // a person decides
        'paid' => ['needs_review'],                                   // a discrepancy found after the fact
    ];

    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('method_code', 30);
            $table->string('provider', 30);
            $table->bigInteger('amount_minor');                       // what was asked for
            $table->char('currency', 3)->default('BDT');
            $table->bigInteger('captured_minor')->default(0);         // what has really been received
            $table->bigInteger('refunded_minor')->default(0);         // what has been given back (money out or promised)
            $table->string('status', 16)->default('initiated');
            $table->dateTime('expires_at', 6)->nullable();            // online payments time out; the held stock goes back then
            $table->dateTime('paid_at', 6)->nullable();
            $table->timestamps(6);

            $table->index('order_id');                                // the payments of one order
            $table->index(['status', 'created_at']);                  // reconciliation: pending payments older than N minutes; finance queues
            $table->index(['status', 'expires_at']);                  // the expiry job
        });

        $checks = [
            'status' => "status IN ('initiated','pending','paid','failed','cancelled','expired','needs_review')",
            'amounts' => 'amount_minor > 0 AND captured_minor >= 0 AND captured_minor <= amount_minor AND refunded_minor >= 0 AND refunded_minor <= captured_minor',
            'paid' => '(captured_minor = 0 OR paid_at IS NOT NULL)',
        ];
        foreach ($checks as $name => $sql) {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_{$name}_check CHECK ({$sql})");
        }

        AppendOnly::protect('payments', ['status', 'captured_minor', 'refunded_minor', 'expires_at', 'paid_at', 'updated_at']);
        StatusFlow::install('payments', 'status', self::FLOW);
    }

    public function down(): void
    {
        StatusFlow::remove('payments');
        DB::unprepared('DROP TRIGGER IF EXISTS `payments_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `payments_no_update`');
        Schema::dropIfExists('payments');
    }
};
