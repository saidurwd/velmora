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
        'initiated' => ['pending', 'succeeded', 'failed', 'cancelled', 'expired'],
        'pending' => ['succeeded', 'failed', 'cancelled', 'expired'],
    ];

    public function up(): void
    {
        // One row per attempt to take the money. A retry is a new transaction on the same payment.
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('merchant_ref', 64)->unique();             // what we send as the transaction id: order number + attempt, e.g. VM-2610-000123-2
            $table->unsignedSmallInteger('attempt');
            $table->string('provider_session', 80)->nullable();       // the gateway's session key
            $table->string('provider_validation_id', 80)->nullable(); // the gateway's validation id
            $table->string('provider_txn_id', 80)->nullable();        // the bank transaction id (needed to refund)
            $table->string('status', 16)->default('initiated');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('BDT');
            $table->json('request_summary')->nullable();              // what we sent, with secrets removed
            $table->dateTime('verified_at', 6)->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->timestamps(6);

            $table->unique(['provider', 'provider_txn_id']);          // a bank transaction can belong to one attempt only
            $table->unique(['payment_id', 'attempt']);                // attempts are numbered 1, 2, 3 … per payment
            $table->index('payment_id');                              // the attempts of one payment
            $table->index(['status', 'created_at']);                  // reconciliation of pending attempts
        });

        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_status_check CHECK (status IN ('initiated','pending','succeeded','failed','cancelled','expired'))");
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_amount_check CHECK (amount_minor > 0 AND attempt >= 1)');

        AppendOnly::protect('payment_transactions', ['status', 'provider_session', 'provider_validation_id', 'provider_txn_id', 'verified_at', 'failure_code', 'updated_at']);
        StatusFlow::install('payment_transactions', 'status', self::FLOW, setOnce: ['provider_txn_id', 'provider_validation_id']);
    }

    public function down(): void
    {
        StatusFlow::remove('payment_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS `payment_transactions_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `payment_transactions_no_update`');
        Schema::dropIfExists('payment_transactions');
    }
};
