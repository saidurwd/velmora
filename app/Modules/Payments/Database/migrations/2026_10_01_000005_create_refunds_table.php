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
    private const FLOW = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['processing', 'succeeded', 'failed'],   // a manual refund goes straight to succeeded once the proof is recorded
        'processing' => ['succeeded', 'failed'],
    ];

    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('BDT');
            $table->string('status', 12)->default('requested');
            $table->string('method', 10);                              // gateway | manual
            $table->string('reason', 255);
            $table->char('refund_ref', 30)->unique();                  // our unique id for this refund; sent to the gateway as refund_trans_id
            $table->string('provider_refund_id', 80)->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('proof_ref', 191)->nullable();              // bank slip / bKash reference for a manual refund
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps(6);

            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['payment_id', 'status']);                   // the over-refund guard: sum of live refunds of one payment
            $table->index('order_id');                                 // refunds shown on the order
            $table->index(['status', 'created_at']);                   // the refund queue
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('requested','approved','processing','succeeded','failed','rejected'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_method_check CHECK (method IN ('gateway','manual'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_minor > 0)');

        AppendOnly::protect('refunds', ['status', 'provider_refund_id', 'approved_by', 'proof_ref', 'failure_reason', 'updated_at']);
        StatusFlow::install('refunds', 'status', self::FLOW, setOnce: ['provider_refund_id']);
    }

    public function down(): void
    {
        StatusFlow::remove('refunds');
        DB::unprepared('DROP TRIGGER IF EXISTS `refunds_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `refunds_no_update`');
        Schema::dropIfExists('refunds');
    }
};
