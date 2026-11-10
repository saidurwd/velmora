<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Why an order was cancelled, as a code. Only a cancellation row carries one; NULL for cancellations made before this column existed (never guessed from the free text).
        // The history table refuses UPDATE and DELETE, and its trigger does not name columns, so the new column is protected already.
        DB::statement('ALTER TABLE order_status_history ADD COLUMN reason_code VARCHAR(30) NULL AFTER reason');
        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_reason_code_check CHECK (reason_code IS NULL OR (axis = 'status' AND to_state = 'cancelled' AND reason_code IN ('customer_request','out_of_stock','payment_failed','address_problem','fraud_or_risk','duplicate_order','other')))");
        // Report query: cancellations in a period, grouped by code (axis = status, to_state = cancelled, created_at between).
        DB::statement('ALTER TABLE order_status_history ADD INDEX order_status_history_cancel_report_index (axis, to_state, created_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_status_history DROP INDEX order_status_history_cancel_report_index');
        DB::statement('ALTER TABLE order_status_history DROP CONSTRAINT order_status_history_reason_code_check');
        DB::statement('ALTER TABLE order_status_history DROP COLUMN reason_code');
    }
};
