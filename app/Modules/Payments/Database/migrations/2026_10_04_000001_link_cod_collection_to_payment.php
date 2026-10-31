<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash collected at the door becomes a normal payment (so refunds and reports treat it like any other money received).
        Schema::table('cod_collections', function (Blueprint $table): void {
            $table->foreignId('payment_id')->nullable()->after('order_id')->constrained('payments')->restrictOnDelete();
            $table->unique('payment_id');                              // one collection per payment
            $table->unique('order_id', 'cod_collections_order_once');  // replaced by the one-manual-per-order marker in the next migration
        });

        AppendOnly::protect('cod_collections', ['recorded_by']);       // the frozen columns are listed at creation, so re-create the trigger
    }

    public function down(): void
    {
        Schema::table('cod_collections', function (Blueprint $table): void {
            $table->dropUnique('cod_collections_order_once');
            $table->dropForeign(['payment_id']);
            $table->dropUnique(['payment_id']);
            $table->dropColumn('payment_id');
        });
        AppendOnly::protect('cod_collections', ['recorded_by']);
    }
};
