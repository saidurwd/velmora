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
        // Couriers usually keep their delivery charge out of the cash they hand over. What arrived plus what the courier kept must add up to the cash collected.
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->bigInteger('fee_minor')->default(0)->after('amount_minor');
        });
        DB::statement('ALTER TABLE cod_remittances ADD CONSTRAINT cod_remittances_fee_check CHECK (fee_minor >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cod_remittances DROP CONSTRAINT cod_remittances_fee_check');
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropColumn('fee_minor');
        });
    }
};
