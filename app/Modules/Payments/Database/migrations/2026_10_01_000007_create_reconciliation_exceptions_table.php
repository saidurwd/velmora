<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Things finance has to look at: a payment that does not match, money that arrived late, cash that came up short.
        Schema::create('reconciliation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('type', 30);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('reference', 120)->nullable();              // provider transaction id, remittance statement, ...
            $table->json('details');
            $table->string('status', 10)->default('open');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->string('resolution', 255)->nullable();
            $table->timestamps(6);

            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'type']);                         // the exceptions screen: what is open
            $table->index('payment_id');
            $table->unique(['type', 'reference']);                     // the same problem is raised once
        });

        DB::statement("ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_check CHECK (status IN ('open','resolved','ignored') AND type IN ('unmatched_provider_txn','amount_mismatch','currency_mismatch','orphan_payment','long_pending','late_success','duplicate_payment','cod_short','cod_over','refund_stuck'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
    }
};
