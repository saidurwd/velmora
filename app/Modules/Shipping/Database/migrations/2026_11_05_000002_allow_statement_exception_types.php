<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = "'unknown_status','attempts_exhausted','stuck','unknown_consignment','returned_to_origin'";

    public function up(): void
    {
        DB::statement('ALTER TABLE shipment_exceptions DROP CONSTRAINT shipment_exceptions_check');
        DB::statement("ALTER TABLE shipment_exceptions ADD CONSTRAINT shipment_exceptions_check CHECK (status IN ('open','resolved','ignored') AND type IN (".self::OLD.",'statement_unknown_parcel','statement_status_mismatch','statement_cod_mismatch','statement_missing_parcel','statement_already_remitted','statement_money_mismatch'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shipment_exceptions DROP CONSTRAINT shipment_exceptions_check');
        DB::statement("ALTER TABLE shipment_exceptions ADD CONSTRAINT shipment_exceptions_check CHECK (status IN ('open','resolved','ignored') AND type IN (".self::OLD.'))');
    }
};
